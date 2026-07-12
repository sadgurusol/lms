<?php

use App\Entitlements\EntitlementResolver;
use App\Entitlements\Grant;
use App\Models\CompGrant;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Catalog\ManageProduct;

beforeEach(function () {
    [$this->course] = publishedTextbookCourse();
    $this->product = Product::factory()->create();
    app(ManageProduct::class)->addCourse($this->product, $this->course);

    $this->plan = Plan::factory()->create(['product_id' => $this->product->id]);
    $this->user = User::factory()->create();
    $this->resolver = fn () => app(EntitlementResolver::class);
});

function subscribe(string $status = Subscription::ACTIVE, ?Carbon\Carbon $periodEnd = null): Subscription
{
    return Subscription::factory()->create([
        'user_id' => test()->user->id,
        'plan_id' => test()->plan->id,
        'status' => $status,
        'current_period_end' => $periodEnd ?? now()->addMonth(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Subscriptions entitle
|--------------------------------------------------------------------------
*/

it('entitles an active subscriber', function () {
    subscribe();

    $grant = ($this->resolver)()->grantFor($this->user, $this->course);

    expect($grant)->not->toBeNull()
        ->and($grant->source)->toBe(Grant::SOURCE_SUBSCRIPTION);
});

it('entitles a trialing subscriber', function () {
    subscribe(Subscription::TRIALING);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();
});

/**
 * A failed renewal starts a dunning cycle. Locking a learner out on the first
 * declined card — mid-term, while the provider is still retrying — loses the
 * customer you were trying to bill.
 */
it('keeps a past_due subscriber entitled through the paid period', function () {
    subscribe(Subscription::PAST_DUE);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();
});

/** Cancelling means "do not renew", not "refund the month I already paid for". */
it('keeps a cancelled subscriber entitled until the period ends', function () {
    $subscription = subscribe(Subscription::CANCELED, periodEnd: now()->addDays(10));

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    $this->travel(11)->days();

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse()
        ->and($subscription->fresh()->isEntitling())->toBeFalse();
});

it('denies an expired subscriber', function () {
    Subscription::factory()->expired()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
    ]);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

it('denies a subscriber whose period has already ended, whatever the status says', function () {
    subscribe(Subscription::ACTIVE, periodEnd: now()->subDay());

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

/**
 * The resolver re-checks a grant's expiry on read, so a subscription lapsing
 * inside the five-minute cache window lapses on time.
 */
it('lapses on time even inside the cache window', function () {
    subscribe(Subscription::ACTIVE, periodEnd: now()->addMinutes(2));

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    $this->travel(3)->minutes();

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

it('busts the entitlement cache when a webhook changes the subscription', function () {
    $subscription = subscribe(Subscription::ACTIVE);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    $subscription->update(['status' => Subscription::EXPIRED]);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Purchases entitle, until refunded
|--------------------------------------------------------------------------
*/

it('entitles a one-time purchaser, permanently', function () {
    Purchase::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'amount_minor' => 199900,
        'currency' => 'INR',
        'provider' => 'razorpay',
        'provider_ref' => 'pay_1',
    ]);

    $grant = ($this->resolver)()->grantFor($this->user, $this->course);

    expect($grant->source)->toBe(Grant::SOURCE_PURCHASE)
        ->and($grant->expiresAt)->toBeNull();

    $this->travel(5)->years();

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();
});

it('revokes a refunded purchase', function () {
    $purchase = Purchase::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'amount_minor' => 199900,
        'currency' => 'INR',
        'provider' => 'razorpay',
        'provider_ref' => 'pay_2',
    ]);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeTrue();

    $purchase->update(['refunded_at' => now()]);

    expect(($this->resolver)()->entitles($this->user, $this->course))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Source priority
|--------------------------------------------------------------------------
*/

/**
 * When two sources cover the same product, the learner is attributed to the one
 * they are actually paying for. Revenue attribution falls out of the grant
 * source stamped on every activity event.
 */
it('prefers a subscription over a purchase over a comp grant', function () {
    CompGrant::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'reason' => CompGrant::REASON_TRIAL,
        'starts_at' => now()->subMinute(),
    ]);

    expect(($this->resolver)()->grantFor($this->user, $this->course)->source)->toBe(Grant::SOURCE_COMP);

    Purchase::create([
        'user_id' => $this->user->id, 'product_id' => $this->product->id,
        'amount_minor' => 1, 'currency' => 'INR', 'provider' => 'razorpay', 'provider_ref' => 'pay_3',
    ]);

    expect(($this->resolver)()->grantFor($this->user, $this->course)->source)->toBe(Grant::SOURCE_PURCHASE);

    subscribe();

    expect(($this->resolver)()->grantFor($this->user, $this->course)->source)->toBe(Grant::SOURCE_SUBSCRIPTION);
});

/*
|--------------------------------------------------------------------------
| Entitlement lives on the user, not the platform
|--------------------------------------------------------------------------
*/

/**
 * A subscription bought on the web must unlock the mobile app, and one bought
 * through IAP must unlock the web. Nothing in the resolver knows what a platform
 * is; `subscriptions.provider` is a billing detail, not an access rule.
 */
it('unlocks the same content regardless of which platform sold the subscription', function () {
    foreach (['razorpay', 'apple', 'google', 'stripe'] as $provider) {
        $user = User::factory()->create();

        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $this->plan->id,
            'provider' => $provider,
            'provider_sub_id' => "sub_{$provider}",
            'status' => Subscription::ACTIVE,
        ]);

        expect(($this->resolver)()->entitles($user, $this->course))
            ->toBeTrue("a subscription sold by [{$provider}] should unlock the course");
    }
});

it('serves the course over the api to a subscriber', function () {
    subscribe();

    $this->actingAs($this->user)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertOk();

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/courses')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('paywalls the course once the subscription expires', function () {
    $subscription = subscribe();

    $this->actingAs($this->user)->getJson("/api/v1/me/courses/{$this->course->id}/content")->assertOk();

    $subscription->update(['status' => Subscription::EXPIRED, 'current_period_end' => now()->subDay()]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/me/courses/{$this->course->id}/content")
        ->assertForbidden()
        ->assertJsonPath('cta.kind', 'paywall');
});

/*
|--------------------------------------------------------------------------
| Money
|--------------------------------------------------------------------------
*/

it('stores money as integer minor units', function () {
    expect($this->plan->price_minor)->toBe(49900)
        ->and($this->plan->price_minor)->toBeInt();
});

it('refuses a negative price', function () {
    expectDatabaseRejection(
        fn () => Plan::factory()->create(['price_minor' => -1]),
        'plans_price_check',
    );
});
