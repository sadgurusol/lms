<?php

namespace App\Billing\Gateways;

use App\Billing\PaymentGateway;
use App\Billing\PurchaseEventData;
use App\Billing\PurchaseIntent;
use App\Billing\SubscriptionIntent;
use App\Billing\WebhookEventData;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class RazorpayGateway implements PaymentGateway
{
    private const API = 'https://api.razorpay.com/v1';

    /**
     * Razorpay's vocabulary → ours.
     *
     * Anything unmapped is stored and ignored rather than guessed at. A provider
     * that adds an event type must not be able to move a subscription into a
     * status nobody chose.
     */
    private const STATUS_MAP = [
        'subscription.authenticated' => Subscription::TRIALING,
        'subscription.activated' => Subscription::ACTIVE,
        'subscription.charged' => Subscription::ACTIVE,
        'subscription.pending' => Subscription::PAST_DUE,
        'subscription.halted' => Subscription::PAST_DUE,
        'subscription.cancelled' => Subscription::CANCELED,
        'subscription.paused' => Subscription::PAST_DUE,
        'subscription.completed' => Subscription::EXPIRED,
        'subscription.expired' => Subscription::EXPIRED,
    ];

    public function name(): string
    {
        return 'razorpay';
    }

    public function configured(): bool
    {
        return filled(config('payments.razorpay.key_id')) && filled(config('payments.razorpay.key_secret'));
    }

    public function createSubscription(User $user, Plan $plan): SubscriptionIntent
    {
        if ($plan->provider_ref === null) {
            throw new RuntimeException("Plan [{$plan->code}] has no Razorpay plan id.");
        }

        $response = Http::withBasicAuth(config('payments.razorpay.key_id'), config('payments.razorpay.key_secret'))
            ->asJson()
            ->post(self::API.'/subscriptions', [
                'plan_id' => $plan->provider_ref,
                'total_count' => $plan->interval === Plan::YEARLY ? 10 : 120,
                'customer_notify' => 1,
                'notes' => ['user_id' => $user->id, 'plan_code' => $plan->code],
            ])
            ->throw();

        return new SubscriptionIntent(
            providerSubscriptionId: $response->json('id'),
            checkoutUrl: $response->json('short_url'),
        );
    }

    /**
     * A one-time buy is a payment link, not a subscription.
     *
     * The link carries `notes` we can read back off the webhook — the provider
     * has no idea what a product is, so the association has to travel with the
     * payment.
     */
    public function createPaymentLink(User $user, Plan $plan): PurchaseIntent
    {
        $response = Http::withBasicAuth(config('payments.razorpay.key_id'), config('payments.razorpay.key_secret'))
            ->asJson()
            ->post(self::API.'/payment_links', [
                'amount' => $plan->price_minor,
                'currency' => $plan->currency,
                'description' => $plan->name,
                'notify' => ['sms' => false, 'email' => false],
                'notes' => ['user_id' => $user->id, 'plan_id' => $plan->id],
            ])
            ->throw();

        return new PurchaseIntent(
            providerLinkId: $response->json('id'),
            checkoutUrl: $response->json('short_url'),
        );
    }

    public function parsePurchaseEvent(array $payload): ?PurchaseEventData
    {
        $type = (string) ($payload['event'] ?? '');
        $occurredAt = Carbon::createFromTimestamp((int) ($payload['created_at'] ?? time()));

        if ($type === 'payment_link.paid') {
            $payment = $payload['payload']['payment']['entity'] ?? [];
            $notes = $payload['payload']['payment_link']['entity']['notes'] ?? [];

            if (! isset($payment['id'], $notes['user_id'], $notes['plan_id'])) {
                return null;
            }

            return new PurchaseEventData(
                providerPaymentId: (string) $payment['id'],
                occurredAt: $occurredAt,
                isRefund: false,
                userId: (string) $notes['user_id'],
                planId: (string) $notes['plan_id'],
                amountMinor: (int) ($payment['amount'] ?? 0),
                currency: (string) ($payment['currency'] ?? 'INR'),
            );
        }

        if ($type === 'refund.processed') {
            $refund = $payload['payload']['refund']['entity'] ?? [];

            if (! isset($refund['payment_id'])) {
                return null;
            }

            return new PurchaseEventData(
                providerPaymentId: (string) $refund['payment_id'],
                occurredAt: $occurredAt,
                isRefund: true,
            );
        }

        return null;
    }

    /**
     * HMAC-SHA256 over the raw request body.
     *
     * `hash_equals`, not `===`: a timing-safe comparison costs nothing and a
     * naive one leaks the expected signature a byte at a time.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = config('payments.razorpay.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            // Fail closed. An unconfigured secret must never mean "accept
            // everything" — that is an unauthenticated write endpoint.
            throw new RuntimeException('No Razorpay webhook secret is configured.');
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function parseEvent(array $payload): WebhookEventData
    {
        $type = (string) ($payload['event'] ?? '');
        $subscription = $payload['payload']['subscription']['entity'] ?? null;

        return new WebhookEventData(
            // Razorpay's `X-Razorpay-Event-Id` header is authoritative, but the
            // body carries a stable fallback for replays from the dashboard.
            eventId: (string) ($payload['id'] ?? ($type.':'.($subscription['id'] ?? '').':'.($payload['created_at'] ?? ''))),
            type: $type,
            providerSubscriptionId: $subscription['id'] ?? null,
            status: self::STATUS_MAP[$type] ?? null,
            occurredAt: Carbon::createFromTimestamp((int) ($payload['created_at'] ?? time())),
            currentPeriodEnd: isset($subscription['current_end'])
                ? Carbon::createFromTimestamp((int) $subscription['current_end'])
                : null,
        );
    }
}
