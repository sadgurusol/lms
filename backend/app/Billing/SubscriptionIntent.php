<?php

namespace App\Billing;

/** What the client needs to complete a checkout. */
final class SubscriptionIntent
{
    public function __construct(
        public readonly string $providerSubscriptionId,
        public readonly string $checkoutUrl,
    ) {}
}
