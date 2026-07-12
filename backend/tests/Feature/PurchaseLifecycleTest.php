<?php

use App\Entitlements\EntitlementResolver;
use App\Entitlements\Grant;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\ApplyPurchaseEvent;
use App\Services\Billing\ApplySubscriptionEvent;
use App\Services\Catalog\ManageProduct;
use Illuminate\Support\Facades\Http;

const PURCHASE_SECRET = 'whsec_purchase';

beforeEach(function () {
    config()->set('payments.razorpay.webhook_secret', PURCHASE_SECRET);
    config()->set('payments.razorpay.key_id', 'k');
    config()->set('payments.razorpay.key_secret', 's');

    [$this->course] = publishedTextbookCourse();
    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);

    $this->plan = Plan::factory()->create([
        'code' => 'LIFETIME-1999',
        'product_id' => $this->product->id,
        'interval' => Plan::ONE_TIME,
        'price_minor' => 199900,
        'currency' => 'INR',
    ]);

    $this->learner = User::factory()->create();
    $this->resolver = fn () => app(EntitlementResolver::class);
});

function deliverSigned(array $body, string $eventId): void
{
    $raw = json_encode($body, JSON_THROW_ON_ERROR);

    test()->call('POST', '/api/v1/webhooks/razorpay', server: [
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $raw, PURCHASE_SECRET),
        'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
        'CONTENT_TYPE' => 'application/json',
    ], content: $raw);
}

function linkPaid(string $paymentId, string $userId, string $planId, int $amount = 199900): array
{
    return [
        'event' => 'payment_link.paid',
        'created_at' => now()->timestamp,
        'payload' => [
            'payment' => ['entity' => ['id' => $paymentId, 'amount' => $amount, 'currency' => 'INR']],
            'payment_link' => ['entity' => ['id' => 'plink_1', 'notes' => ['user_id' => $userId, 'plan_id' => $planId]]],
        ],
    ];
}

function refundProcessed(string $paymentId): array
{
    return [
        'event' => 'refund.processed',
        'created_at' => now()->timestamp,
        'payload' => ['refund' => ['entity' => ['id' => 'rfnd_1', 'payment_id' => $paymentId]]],
    ];
}

/*
|--------------------------------------------------------------------------
| The gap this closes
|--------------------------------------------------------------------------
*/

/**
 * A one-time plan has no renewal, so no activation webhook will ever arrive for
 * it. Opening a subscription would leave a `pending` row nothing can move, and a
 * learner who paid and got nothing.
 */
it('refuses to open a subscription for a one-time plan', function () {
    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/subscriptions', ['plan_code' => 'LIFETIME-1999'])
        ->assertStatus(422);

    expect(Subscription::count())->toBe(0);
});

it('refuses to open a payment link for a recurring plan', function () {
    $monthly = Plan::factory()->create(['code' => 'MONTHLY', 'interval' => Plan::MONTHLY]);

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/purchases', ['plan_code' => 'MONTHLY'])
        ->assertStatus(422);

    expect(Purchase::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Checkout → capture → entitlement
|--------------------------------------------------------------------------
*/

it('carries a learner from a payment link through capture to access, then a refund', function () {
    Http::fake([
        'api.razorpay.com/v1/payment_links' => Http::response([
            'id' => 'plink_1', 'short_url' => 'https://rzp.io/i/buy',
        ]),
    ]);

    // Paywalled before buying.
    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden();

    // Checkout opens a link and grants nothing.
    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/purchases', ['plan_code' => 'LIFETIME-1999'])
        ->assertStatus(201)
        ->assertJsonPath('checkout_url', 'https://rzp.io/i/buy');

    expect(Purchase::count())->toBe(0);

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden();

    // The learner pays. The capture webhook creates the purchase.
    deliverSigned(linkPaid('pay_ABC', $this->learner->id, $this->plan->id), 'evt_paid');

    $purchase = Purchase::firstOrFail();
    expect($purchase->product_id)->toBe($this->product->id)
        ->and($purchase->amount_minor)->toBe(199900)
        ->and($purchase->provider_ref)->toBe('pay_ABC');

    $grant = ($this->resolver)()->grantFor($this->learner, $this->course);
    expect($grant->source)->toBe(Grant::SOURCE_PURCHASE)
        ->and($grant->expiresAt)->toBeNull();

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    // A one-time buy does not lapse.
    $this->travel(5)->years();
    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    // The learner is refunded. Access ends.
    deliverSigned(refundProcessed('pay_ABC'), 'evt_refund');

    expect(Purchase::firstOrFail()->refunded_at)->not->toBeNull();

    $this->actingAs($this->learner)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden()
        ->assertJsonPath('cta.kind', 'paywall');
});

/*
|--------------------------------------------------------------------------
| Idempotency and trust
|--------------------------------------------------------------------------
*/

it('creates one purchase however many times the capture webhook is delivered', function () {
    deliverSigned(linkPaid('pay_X', $this->learner->id, $this->plan->id), 'evt_1');
    deliverSigned(linkPaid('pay_X', $this->learner->id, $this->plan->id), 'evt_1');   // redelivery
    deliverSigned(linkPaid('pay_X', $this->learner->id, $this->plan->id), 'evt_2');   // new event id, same payment

    expect(Purchase::count())->toBe(1)
        ->and(WebhookEvent::count())->toBe(2);
});

/** The payment link's amount travels through a client-visible URL. The plan's does not. */
it('bills the plan price, not the amount the webhook reports', function () {
    deliverSigned(linkPaid('pay_Y', $this->learner->id, $this->plan->id, amount: 1), 'evt_cheap');

    expect(Purchase::firstOrFail()->amount_minor)->toBe(199900);
});

it('does not refund twice, so the audit trail keeps the first timestamp', function () {
    deliverSigned(linkPaid('pay_Z', $this->learner->id, $this->plan->id), 'evt_paid');
    deliverSigned(refundProcessed('pay_Z'), 'evt_r1');

    $first = Purchase::firstOrFail()->refunded_at;

    $this->travel(1)->hour();
    deliverSigned(refundProcessed('pay_Z'), 'evt_r2');

    expect(Purchase::firstOrFail()->refunded_at->equalTo($first))->toBeTrue();
});

it('records a refund for a payment it has never seen', function () {
    deliverSigned(refundProcessed('pay_GHOST'), 'evt_ghost');

    expect(WebhookEvent::firstOrFail()->error)->toContain('No purchase for payment [pay_GHOST]')
        ->and(Purchase::count())->toBe(0);
});

it('records a capture naming a plan that does not exist', function () {
    deliverSigned(linkPaid('pay_Q', $this->learner->id, Str::uuid7()->toString()), 'evt_badplan');

    expect(WebhookEvent::firstOrFail()->error)->toContain('Unknown plan')
        ->and(Purchase::count())->toBe(0);
});

it('refuses a second checkout for something already owned', function () {
    Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'plink_2', 'short_url' => 'https://rzp.io/i/x'])]);

    deliverSigned(linkPaid('pay_OWNED', $this->learner->id, $this->plan->id), 'evt_own');

    $this->actingAs($this->learner)
        ->postJson('/api/v1/me/purchases', ['plan_code' => 'LIFETIME-1999'])
        ->assertStatus(422);

    expect(Purchase::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| One endpoint, two kinds of event
|--------------------------------------------------------------------------
*/

/** One endpoint, and a purchase event reaches the purchase applier. */
it('routes a purchase event to the purchase applier', function () {
    deliverSigned(linkPaid('pay_R', $this->learner->id, $this->plan->id), 'evt_route');

    $event = WebhookEvent::firstOrFail();

    expect($event->processed_at)->not->toBeNull()
        ->and($event->error)->toBeNull()
        ->and(Purchase::count())->toBe(1);
});

it('routes a subscription event to the subscription applier', function () {
    $subscription = Subscription::factory()->create([
        'user_id' => $this->learner->id,
        'provider_sub_id' => 'sub_ROUTE',
        'status' => Subscription::PENDING,
    ]);

    deliverSigned([
        'event' => 'subscription.activated',
        'created_at' => now()->timestamp,
        'payload' => ['subscription' => ['entity' => [
            'id' => 'sub_ROUTE', 'current_end' => now()->addMonth()->timestamp,
        ]]],
    ], 'evt_sub');

    expect($subscription->fresh()->status)->toBe(Subscription::ACTIVE)
        ->and(Purchase::count())->toBe(0);
});

/**
 * Each applier declines what is not its own, so neither claims an event the
 * other should have. A payment event must not touch subscriptions, and a
 * subscription event must not mint a purchase.
 */
it('lets each applier decline what is not its own', function () {
    $purchaseEvent = WebhookEvent::create([
        'provider' => 'razorpay', 'provider_event_id' => 'evt_p', 'type' => 'payment_link.paid',
        'payload' => linkPaid('pay_D', $this->learner->id, $this->plan->id),
        'occurred_at' => now(),
    ]);

    expect(app(ApplySubscriptionEvent::class)->handle($purchaseEvent))->toBeFalse()
        ->and($purchaseEvent->fresh()->processed_at)->toBeNull();

    $subscriptionEvent = WebhookEvent::create([
        'provider' => 'razorpay', 'provider_event_id' => 'evt_s', 'type' => 'subscription.activated',
        'payload' => ['event' => 'subscription.activated', 'created_at' => now()->timestamp,
            'payload' => ['subscription' => ['entity' => ['id' => 'sub_D']]]],
        'occurred_at' => now(),
    ]);

    expect(app(ApplyPurchaseEvent::class)->handle($subscriptionEvent))->toBeFalse()
        ->and($subscriptionEvent->fresh()->processed_at)->toBeNull();
});

/** Providers send plenty we do not care about. Record them; do not retry forever. */
it('marks an event nobody claims as seen, without an error', function () {
    deliverSigned([
        'event' => 'settlement.processed',
        'created_at' => now()->timestamp,
        'payload' => [],
    ], 'evt_ignored');

    $event = WebhookEvent::firstOrFail();

    expect($event->processed_at)->not->toBeNull()
        ->and($event->error)->toBeNull()
        ->and(Purchase::count())->toBe(0)
        ->and(Subscription::count())->toBe(0);
});
