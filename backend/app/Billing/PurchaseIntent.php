<?php

namespace App\Billing;

/** A payment link opened for a one-time buy. */
final class PurchaseIntent
{
    public function __construct(
        public readonly string $providerLinkId,
        public readonly string $checkoutUrl,
    ) {}
}
