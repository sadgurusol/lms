<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\User;

interface PaymentGateway
{
    public function name(): string;

    /** Whether the gateway has the credentials it needs to open a checkout. */
    public function configured(): bool;

    /** Open a subscription at the provider. Activation arrives by webhook, never here. */
    public function createSubscription(User $user, Plan $plan): SubscriptionIntent;

    /** Open a payment link for a one-time plan. Capture arrives by webhook. */
    public function createPaymentLink(User $user, Plan $plan): PurchaseIntent;

    /**
     * Verify a webhook came from the provider.
     *
     * `$rawBody` must be the bytes as received. Re-encoding the decoded JSON
     * reorders keys and changes whitespace, and the signature will never match.
     */
    public function verifySignature(string $rawBody, string $signature): bool;

    /** Normalise the provider's subscription vocabulary into our own. */
    public function parseEvent(array $payload): WebhookEventData;

    /** Null when the event is not about a one-time payment. */
    public function parsePurchaseEvent(array $payload): ?PurchaseEventData;
}
