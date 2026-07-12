<?php

namespace App\Billing;

use Illuminate\Support\Carbon;

/**
 * A one-time payment or its refund, normalised.
 *
 * Keyed on the provider's *payment* id, not the link id: a refund event carries
 * the payment it reverses, and nothing else ties the two together.
 */
final class PurchaseEventData
{
    public function __construct(
        public readonly string $providerPaymentId,
        public readonly Carbon $occurredAt,
        public readonly bool $isRefund,
        public readonly ?string $userId = null,
        public readonly ?string $planId = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
    ) {}

    public function isCapture(): bool
    {
        return ! $this->isRefund;
    }
}
