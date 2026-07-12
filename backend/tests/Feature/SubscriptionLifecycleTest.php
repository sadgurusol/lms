<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\ApplyWebhookEvent;
use App\Services\Catalog\ManageProduct;
use Illuminate\Support\Facades\Http;

/**
 * The M8 acceptance criterion, verbatim from docs/09-roadmap.md:
 *
 *   "a subscription bought on the web unlocks the mobile app, a cancellation
 *    ends access at period end, and a replayed webhook is a no-op."
 */
beforeEach(function () {
    config()->set('payments.razorpay.webhook_secret', 'whsec_lifecycle');
    config()->set('payments.razorpay.key_id', 'k');
    config()->set('payments.razorpay.key_secret', 's');

    [$this->course] = publishedTextbookCourse();
    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);

    $this->plan = Plan::factory()->create([
        'code' => 'MONTHLY-499',
        'product_id' => $this->product->id,
        'provider_ref' => 'plan_RZP',
    ]);

    $this->learner = User::factory()->create();
});

/** Deliver a signed webhook exactly as Razorpay would. */
function deliver(string $type, string $eventId, array $entity = [], ?int $createdAt = null): void
{
    $body = [
        'event' => $type,
        'created_at' => $createdAt ?? now()->timestamp,
        'payload' => ['subscription' => ['entity' => [
            'id' => 'sub_LIFECYCLE',
            'current_end' => now()->addMonth()->timestamp,
            ...$entity,
        ]]],
    ];

    $raw = json_encode($body, JSON_THROW_ON_ERROR);

    test()->call('POST', '/api/v1/webhooks/razorpay', server: [
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $raw, 'whsec_lifecycle'),
        'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
        'CONTENT_TYPE' => 'application/json',
    ], content: $raw);
}

it('carries a learner from checkout through activation, cancellation and expiry', function () {
    Http::fake([
        'api.razorpay.com/*' => Http::response(['id' => 'sub_LIFECYCLE', 'short_url' => 'https://rzp.io/i/z']),
    ]);

    // ---- The paywall, before anything is bought ----
    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden()
        ->assertJsonPath('cta.kind', 'paywall');

    // ---- Checkout on the web. Opens a subscription; grants nothing. ----
    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'MONTHLY-499'])
        ->assertStatus(201)
        ->assertJsonPath('status', Subscription::PENDING)
        ->assertJsonPath('checkout_url', 'https://rzp.io/i/z');

    expect(Subscription::first()->isEntitling())->toBeFalse();

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden();

    // ---- The learner pays. Activation arrives by webhook. ----
    deliver('subscription.activated', 'evt_activate');

    expect(Subscription::first()->status)->toBe(Subscription::ACTIVE);

    // The subscription was bought on the web and unlocks every platform.
    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    $this->actingAs($this->learner)
        ->getJson('/api/v1/me/courses')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // ---- A replayed webhook is a no-op ----
    deliver('subscription.activated', 'evt_activate');
    expect(WebhookEvent::count())->toBe(1);

    // ---- A card fails. Dunning starts; the learner keeps reading. ----
    deliver('subscription.halted', 'evt_halt');

    expect(Subscription::first()->status)->toBe(Subscription::PAST_DUE);
    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    // ---- The card is retried and captured, out of order: the halt webhook
    //      Razorpay retried arrives *after* the charge. It must not win. ----
    $chargedAt = now()->addMinute();
    deliver('subscription.charged', 'evt_charge', createdAt: $chargedAt->timestamp);
    expect(Subscription::first()->status)->toBe(Subscription::ACTIVE);

    deliver('subscription.halted', 'evt_halt_retry', createdAt: now()->subHour()->timestamp);
    expect(Subscription::first()->status)->toBe(Subscription::ACTIVE);

    // ---- The learner cancels. Access runs to the end of the paid period. ----
    $periodEnd = now()->addDays(9);
    deliver('subscription.cancelled', 'evt_cancel',
        entity: ['current_end' => $periodEnd->timestamp],
        createdAt: now()->addMinutes(2)->timestamp,
    );

    $subscription = Subscription::first();
    expect($subscription->status)->toBe(Subscription::CANCELED)
        ->and($subscription->canceled_at)->not->toBeNull();

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    // ---- The period ends. The paywall returns. ----
    $this->travel(10)->days();

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden()
        ->assertJsonPath('cta.kind', 'paywall');

    $this->actingAs($this->learner)
        ->getJson('/api/v1/me/courses')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('answers 503, not 500, when payments are not configured', function () {
    config()->set('payments.razorpay.key_id', null);
    config()->set('payments.razorpay.key_secret', null);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'MONTHLY-499'])
        ->assertStatus(503)
        ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'not set up'));

    expect(Subscription::count())->toBe(0);
});

it('refuses a second checkout while a subscription is already live', function () {
    Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'sub_A', 'short_url' => 'https://rzp.io/i/a'])]);

    Subscription::factory()->create([
        'user_id' => $this->learner->id,
        'plan_id' => $this->plan->id,
        'status' => Subscription::ACTIVE,
    ]);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'MONTHLY-499'])
        ->assertStatus(422);   // a user error (already subscribed), not a server fault

    expect(Subscription::count())->toBe(1);
});

it('allows a fresh checkout after an abandoned one', function () {
    Http::fakeSequence('api.razorpay.com/*')
        ->push(['id' => 'sub_ONE', 'short_url' => 'https://rzp.io/i/1'])
        ->push(['id' => 'sub_TWO', 'short_url' => 'https://rzp.io/i/2']);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'MONTHLY-499'])
        ->assertStatus(201);

    // The learner closes the tab and tries again. A `pending` row must not
    // block them — that is why the live-subscription index excludes it.
    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'MONTHLY-499'])
        ->assertStatus(201);

    expect(Subscription::where('status', Subscription::PENDING)->count())->toBe(2);
});

it('refuses checkout on a retired plan', function () {
    $this->plan->update(['status' => 'retired']);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'MONTHLY-499'])
        ->assertStatus(422);

    expect(Subscription::count())->toBe(0);
});

it('lists a learner s subscriptions with whether each entitles', function () {
    Subscription::factory()->create([
        'user_id' => $this->learner->id,
        'plan_id' => $this->plan->id,
        'status' => Subscription::ACTIVE,
    ]);

    $this->actingAs($this->learner)
        ->getJson('/api/v1/me/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.0.status', Subscription::ACTIVE)
        ->assertJsonPath('data.0.entitles', true)
        ->assertJsonPath('data.0.plan.code', 'MONTHLY-499')
        ->assertJsonPath('data.0.plan.price_minor', 49900);
});

it('processes a webhook through the queued job, idempotently', function () {
    Subscription::factory()->create([
        'user_id' => $this->learner->id,
        'plan_id' => $this->plan->id,
        'provider_sub_id' => 'sub_LIFECYCLE',
        'status' => Subscription::PENDING,
        'current_period_end' => null,
    ]);

    deliver('subscription.activated', 'evt_q');

    $event = WebhookEvent::firstOrFail();
    expect($event->processed_at)->not->toBeNull();

    // At-least-once delivery: the job runs again and must do nothing.
    $before = Subscription::first()->updated_at;
    app(ProcessWebhookEvent::class, ['webhookEventId' => $event->id])
        ->handle(app(ApplyWebhookEvent::class));

    expect(Subscription::first()->updated_at->equalTo($before))->toBeTrue();
});
