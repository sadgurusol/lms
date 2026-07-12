<?php

namespace App\Services\Billing;

use App\Models\WebhookEvent;

/**
 * One webhook endpoint, two kinds of event.
 *
 * Each applier inspects the payload and declines what is not its own, so the
 * order they are tried in does not matter — no event can be claimed by both.
 * What matters is that an event nobody claims is recorded as seen rather than
 * retried forever: providers send plenty we do not care about.
 */
final class ApplyWebhookEvent
{
    public function __construct(
        private readonly ApplyPurchaseEvent $purchases,
        private readonly ApplySubscriptionEvent $subscriptions,
    ) {}

    public function handle(WebhookEvent $event): void
    {
        $claimed = $this->purchases->handle($event)
            || $this->subscriptions->handle($event);

        if (! $claimed) {
            // Not an error — `payment.authorized`, `settlement.processed` and a
            // dozen others are simply none of our business. Kept for replay.
            $event->forceFill(['processed_at' => now()])->save();
        }
    }
}
