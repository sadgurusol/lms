<?php

use App\Billing\Gateways\RazorpayGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payments.razorpay.key_id', 'rzp_test_key');
    config()->set('payments.razorpay.key_secret', 'rzp_test_secret');

    $this->gateway = new RazorpayGateway;
});

it('opens a subscription at the provider and returns a checkout url', function () {
    Http::fake([
        'api.razorpay.com/v1/subscriptions' => Http::response([
            'id' => 'sub_NEW123',
            'short_url' => 'https://rzp.io/i/abc',
        ]),
    ]);

    $user = User::factory()->create();
    $plan = Plan::factory()->create(['provider_ref' => 'plan_XYZ', 'interval' => Plan::MONTHLY]);

    $intent = $this->gateway->createSubscription($user, $plan);

    expect($intent->providerSubscriptionId)->toBe('sub_NEW123')
        ->and($intent->checkoutUrl)->toBe('https://rzp.io/i/abc');

    Http::assertSent(function ($request) use ($user, $plan) {
        return $request['plan_id'] === 'plan_XYZ'
            && $request['notes']['user_id'] === $user->id
            && $request['notes']['plan_code'] === $plan->code;
    });
});

/** Activation arrives by webhook. The checkout call must never grant access. */
it('does not create a local subscription or grant anything', function () {
    Http::fake([
        'api.razorpay.com/*' => Http::response(['id' => 'sub_X', 'short_url' => 'https://rzp.io/i/x']),
    ]);

    $this->gateway->createSubscription(User::factory()->create(), Plan::factory()->create());

    expect(Subscription::count())->toBe(0);
});

it('refuses a plan with no provider id', function () {
    $plan = Plan::factory()->create(['provider_ref' => null]);

    expect(fn () => $this->gateway->createSubscription(User::factory()->create(), $plan))
        ->toThrow(RuntimeException::class, 'no Razorpay plan id');
});

it('surfaces a provider failure rather than swallowing it', function () {
    Http::fake(['api.razorpay.com/*' => Http::response(['error' => 'bad plan'], 400)]);

    expect(fn () => $this->gateway->createSubscription(User::factory()->create(), Plan::factory()->create()))
        ->toThrow(RequestException::class);
});

/*
|--------------------------------------------------------------------------
| Vocabulary normalisation
|--------------------------------------------------------------------------
*/

it('normalises the provider vocabulary into our own statuses', function () {
    $map = [
        'subscription.authenticated' => Subscription::TRIALING,
        'subscription.activated' => Subscription::ACTIVE,
        'subscription.charged' => Subscription::ACTIVE,
        'subscription.pending' => Subscription::PAST_DUE,
        'subscription.halted' => Subscription::PAST_DUE,
        'subscription.cancelled' => Subscription::CANCELED,
        'subscription.completed' => Subscription::EXPIRED,
    ];

    foreach ($map as $providerEvent => $ourStatus) {
        $data = $this->gateway->parseEvent([
            'event' => $providerEvent,
            'created_at' => now()->timestamp,
            'payload' => ['subscription' => ['entity' => ['id' => 'sub_1', 'current_end' => now()->addMonth()->timestamp]]],
        ]);

        expect($data->status)->toBe($ourStatus, "[{$providerEvent}] mapped wrong")
            ->and($data->isSubscriptionEvent())->toBeTrue();
    }
});

/** An event type nobody mapped must not move a subscription anywhere. */
it('yields no status for an unmapped event type', function () {
    $data = $this->gateway->parseEvent([
        'event' => 'payment.dispute.created',
        'created_at' => now()->timestamp,
        'payload' => ['subscription' => ['entity' => ['id' => 'sub_1']]],
    ]);

    expect($data->status)->toBeNull()
        ->and($data->isSubscriptionEvent())->toBeFalse();
});

it('reads the provider clock, not ours', function () {
    $providerTime = now()->subHours(3);

    $data = $this->gateway->parseEvent([
        'event' => 'subscription.activated',
        'created_at' => $providerTime->timestamp,
        'payload' => ['subscription' => ['entity' => ['id' => 'sub_1']]],
    ]);

    expect($data->occurredAt->timestamp)->toBe($providerTime->timestamp);
});

/*
|--------------------------------------------------------------------------
| Signature
|--------------------------------------------------------------------------
*/

it('verifies an hmac over the raw body', function () {
    config()->set('payments.razorpay.webhook_secret', 'shh');

    $body = '{"event":"subscription.activated"}';

    expect($this->gateway->verifySignature($body, hash_hmac('sha256', $body, 'shh')))->toBeTrue()
        ->and($this->gateway->verifySignature($body, hash_hmac('sha256', $body, 'wrong')))->toBeFalse()
        ->and($this->gateway->verifySignature($body.' ', hash_hmac('sha256', $body, 'shh')))->toBeFalse();
});

it('fails closed on a missing secret', function () {
    config()->set('payments.razorpay.webhook_secret', null);

    expect(fn () => $this->gateway->verifySignature('{}', 'anything'))
        ->toThrow(RuntimeException::class, 'No Razorpay webhook secret');
});
