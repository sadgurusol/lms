<?php

namespace App\Services\Billing;

use App\Billing\PaymentGateway;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ApplySubscriptionEvent
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @return bool whether this event was a subscription event at all */
    public function handle(WebhookEvent $event): bool
    {
        $data = $this->gateway->parseEvent($event->payload);

        // An unmapped event type must not move a subscription into a status
        // nobody chose. Decline it; the router decides what "unclaimed" means.
        if (! $data->isSubscriptionEvent()) {
            return false;
        }

        DB::transaction(function () use ($event, $data) {
            $subscription = Subscription::query()
                ->where('provider', $this->gateway->name())
                ->where('provider_sub_id', $data->providerSubscriptionId)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                $event->forceFill([
                    'processed_at' => now(),
                    'error' => "No subscription for {$data->providerSubscriptionId}.",
                ])->save();

                return;
            }

            if ($this->isStale($subscription, $data->occurredAt)) {
                // Webhook order is not guaranteed. A late `payment.failed` must
                // not override the `payment.captured` that came after it.
                $event->forceFill([
                    'processed_at' => now(),
                    'error' => 'Stale event; a newer one has already been applied.',
                ])->save();

                return;
            }

            $subscription->forceFill(array_filter([
                'status' => $data->status,
                'current_period_end' => $data->currentPeriodEnd,
                'provider_event_at' => $data->occurredAt,
                'canceled_at' => $data->status === Subscription::CANCELED ? now() : null,
            ], fn ($value) => $value !== null))->save();

            $event->forceFill(['processed_at' => now()])->save();
        });

        return true;
    }

    private function isStale(Subscription $subscription, Carbon $occurredAt): bool
    {
        return $subscription->provider_event_at !== null
            && $subscription->provider_event_at->greaterThan($occurredAt);
    }
}
