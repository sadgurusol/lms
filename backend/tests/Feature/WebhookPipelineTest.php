<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Services\Billing\ApplySubscriptionEvent;
use App\Services\Billing\ApplyWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

const SECRET = 'whsec_test_1234567890';

beforeEach(function () {
    config()->set('payments.razorpay.webhook_secret', SECRET);

    $this->subscription = Subscription::factory()->create([
        'provider_sub_id' => 'sub_ABC123',
        'status' => Subscription::TRIALING,
        'current_period_end' => now()->addDays(7),
    ]);
});

/** Build a Razorpay-shaped payload. */
function payload(string $event, array $overrides = []): array
{
    return [
        'event' => $event,
        'created_at' => $overrides['created_at'] ?? now()->timestamp,
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => $overrides['sub_id'] ?? 'sub_ABC123',
                    'current_end' => $overrides['current_end'] ?? now()->addMonth()->timestamp,
                ],
            ],
        ],
    ];
}

/**
 * POST a webhook with a correctly computed signature over the exact bytes sent.
 *
 * @return TestResponse<JsonResponse>
 */
function sendWebhook(array $body, ?string $signature = null, ?string $eventId = null): TestResponse
{
    $raw = json_encode($body, JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        '/api/v1/webhooks/razorpay',
        server: array_filter([
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, SECRET),
            'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
            'CONTENT_TYPE' => 'application/json',
        ]),
        content: $raw,
    );
}

/*
|--------------------------------------------------------------------------
| Signature verification
|--------------------------------------------------------------------------
*/

it('accepts a correctly signed webhook', function () {
    Queue::fake();

    sendWebhook(payload('subscription.activated'), eventId: 'evt_1')->assertStatus(202);

    expect(WebhookEvent::count())->toBe(1);
});

it('rejects a webhook with a wrong signature', function () {
    sendWebhook(payload('subscription.activated'), signature: 'deadbeef')->assertStatus(401);

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects a webhook with no signature at all', function () {
    sendWebhook(payload('subscription.activated'), signature: '')->assertStatus(401);
});

/**
 * The signature covers the bytes as received. Verifying against a re-encoded
 * body would reorder keys and drop whitespace, and no honest webhook would ever
 * validate.
 */
it('verifies against the raw body, not a re-encoded one', function () {
    Queue::fake();

    $raw = '{"event":"subscription.activated","created_at":1752130000,'
        .'  "payload" : {"subscription":{"entity":{"id":"sub_ABC123","current_end":1754808400}}}}';

    $this->call('POST', '/api/v1/webhooks/razorpay', server: [
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $raw, SECRET),
        'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_raw',
        'CONTENT_TYPE' => 'application/json',
    ], content: $raw)->assertStatus(202);

    expect(WebhookEvent::first()->provider_event_id)->toBe('evt_raw');
});

/** An unconfigured secret must never mean "accept everything". */
it('fails closed when no webhook secret is configured', function () {
    config()->set('payments.razorpay.webhook_secret', null);

    $this->withoutExceptionHandling();

    expect(fn () => sendWebhook(payload('subscription.activated')))
        ->toThrow(RuntimeException::class, 'No Razorpay webhook secret');

    expect(WebhookEvent::count())->toBe(0);
});

/** Without the handler, a missing secret is a 500 — never an accepted write. */
it('does not accept a webhook when the secret is missing, even unsigned', function () {
    config()->set('payments.razorpay.webhook_secret', '');

    sendWebhook(payload('subscription.activated'), signature: '')->assertStatus(500);

    expect(WebhookEvent::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

it('treats a redelivered event as a duplicate and queues it once', function () {
    Queue::fake();

    sendWebhook(payload('subscription.activated'), eventId: 'evt_1')->assertStatus(202);
    sendWebhook(payload('subscription.activated'), eventId: 'evt_1')->assertStatus(200)
        ->assertJsonPath('status', 'duplicate');

    expect(WebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('is safe to apply the same event twice', function () {
    $event = storeEvent(payload('subscription.activated'));

    app(ApplySubscriptionEvent::class)->handle($event);
    $firstAppliedAt = $event->fresh()->processed_at;

    app(ApplySubscriptionEvent::class)->handle($event->fresh());

    expect($this->subscription->fresh()->status)->toBe(Subscription::ACTIVE)
        ->and($event->fresh()->processed_at)->not->toBeNull()
        ->and($firstAppliedAt)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Applying events
|--------------------------------------------------------------------------
*/

function storeEvent(array $body, string $id = 'evt_x'): WebhookEvent
{
    return WebhookEvent::create([
        'provider' => 'razorpay',
        'provider_event_id' => $id,
        'type' => $body['event'],
        'payload' => $body,
        'occurred_at' => Carbon::createFromTimestamp($body['created_at']),
    ]);
}

it('activates a subscription and extends its period', function () {
    $end = now()->addMonth()->startOfSecond();

    app(ApplySubscriptionEvent::class)->handle(
        storeEvent(payload('subscription.activated', ['current_end' => $end->timestamp]))
    );

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(Subscription::ACTIVE)
        ->and($subscription->current_period_end->timestamp)->toBe($end->timestamp)
        ->and($subscription->provider_event_at)->not->toBeNull();
});

it('maps a failed renewal to past_due, not to expired', function () {
    app(ApplySubscriptionEvent::class)->handle(storeEvent(payload('subscription.halted')));

    expect($this->subscription->fresh()->status)->toBe(Subscription::PAST_DUE);
});

it('records a cancellation with its timestamp', function () {
    app(ApplySubscriptionEvent::class)->handle(storeEvent(payload('subscription.cancelled')));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(Subscription::CANCELED)
        ->and($subscription->canceled_at)->not->toBeNull();
});

/**
 * Providers do not guarantee order. A late `halted` arriving after `activated`
 * would otherwise lock out a learner whose payment succeeded.
 */
it('drops a stale event that arrives after a newer one', function () {
    $newer = payload('subscription.activated', ['created_at' => now()->timestamp]);
    $older = payload('subscription.halted', ['created_at' => now()->subHour()->timestamp]);

    app(ApplySubscriptionEvent::class)->handle(storeEvent($newer, 'evt_new'));
    expect($this->subscription->fresh()->status)->toBe(Subscription::ACTIVE);

    app(ApplySubscriptionEvent::class)->handle(storeEvent($older, 'evt_old'));

    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(Subscription::ACTIVE);

    $event = WebhookEvent::where('provider_event_id', 'evt_old')->firstOrFail();
    expect($event->processed_at)->not->toBeNull()
        ->and($event->error)->toContain('Stale event');
});

it('applies an event that is newer than the last one applied', function () {
    app(ApplySubscriptionEvent::class)->handle(
        storeEvent(payload('subscription.activated', ['created_at' => now()->subHour()->timestamp]), 'evt_a')
    );

    app(ApplySubscriptionEvent::class)->handle(
        storeEvent(payload('subscription.halted', ['created_at' => now()->timestamp]), 'evt_b')
    );

    expect($this->subscription->fresh()->status)->toBe(Subscription::PAST_DUE);
});

/**
 * A provider adding an event type must not move a subscription anywhere. The
 * applier declines it; ApplyWebhookEvent decides what "unclaimed" means.
 */
it('declines an unmapped event type instead of applying it', function () {
    $event = storeEvent(payload('payment.authorized'));

    $claimed = app(ApplySubscriptionEvent::class)->handle($event);

    expect($claimed)->toBeFalse()
        ->and($this->subscription->fresh()->status)->toBe(Subscription::TRIALING)
        ->and($event->fresh()->processed_at)->toBeNull();
});

/** And the router records it as seen, so the provider stops retrying it. */
it('marks an unclaimed event as seen once it reaches the router', function () {
    $event = storeEvent(payload('payment.authorized'));

    app(ApplyWebhookEvent::class)->handle($event);

    expect($event->fresh()->processed_at)->not->toBeNull()
        ->and($event->fresh()->error)->toBeNull()
        ->and($this->subscription->fresh()->status)->toBe(Subscription::TRIALING);
});

it('records an event for a subscription it has never heard of', function () {
    app(ApplySubscriptionEvent::class)->handle(
        storeEvent(payload('subscription.activated', ['sub_id' => 'sub_UNKNOWN']))
    );

    expect(WebhookEvent::first()->error)->toContain('No subscription for sub_UNKNOWN');
});

/*
|--------------------------------------------------------------------------
| Guardrails
|--------------------------------------------------------------------------
*/

it('refuses two live subscriptions to the same plan', function () {
    expectDatabaseRejection(
        fn () => Subscription::factory()->create([
            'user_id' => $this->subscription->user_id,
            'plan_id' => $this->subscription->plan_id,
            'status' => Subscription::ACTIVE,
        ]),
        'one_live_subscription_per_plan',
    );
});

it('allows a new subscription once the old one expired', function () {
    $this->subscription->update(['status' => Subscription::EXPIRED]);

    $second = Subscription::factory()->create([
        'user_id' => $this->subscription->user_id,
        'plan_id' => $this->subscription->plan_id,
        'status' => Subscription::ACTIVE,
    ]);

    expect($second->exists)->toBeTrue();
});

it('refuses two subscriptions claiming the same provider id', function () {
    expectDatabaseRejection(
        fn () => Subscription::factory()->create(['provider_sub_id' => 'sub_ABC123']),
        'subscriptions_provider_sub_unique',
    );
});
