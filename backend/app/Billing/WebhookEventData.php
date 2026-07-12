<?php

namespace App\Billing;

use Illuminate\Support\Carbon;

/**
 * A provider event, normalised at the edge.
 *
 * The resolver must never learn what a `DID_CHANGE_RENEWAL_STATUS` is. Three
 * providers with three vocabularies collapse into one here, or they leak into
 * every downstream branch.
 */
final class WebhookEventData
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $providerSubscriptionId,
        /** One of Subscription::TRIALING|ACTIVE|PAST_DUE|CANCELED|EXPIRED, or null if not a subscription event. */
        public readonly ?string $status,
        public readonly Carbon $occurredAt,
        public readonly ?Carbon $currentPeriodEnd = null,
    ) {}

    public function isSubscriptionEvent(): bool
    {
        return $this->providerSubscriptionId !== null && $this->status !== null;
    }
}
