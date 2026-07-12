<?php

namespace App\Services\Billing;

use App\Billing\PaymentGateway;
use App\Billing\PurchaseEventData;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

final class ApplyPurchaseEvent
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @return bool whether this event was a purchase event at all */
    public function handle(WebhookEvent $event): bool
    {
        $data = $this->gateway->parsePurchaseEvent($event->payload);

        if ($data === null) {
            return false;
        }

        DB::transaction(fn () => $data->isRefund
            ? $this->refund($event, $data)
            : $this->capture($event, $data));

        return true;
    }

    /**
     * Idempotent on the provider's payment id.
     *
     * At-least-once delivery plus a retried job means this runs more than once.
     * `firstOrCreate` against the unique index is what makes it safe, and it is
     * why `purchases.provider_ref` is indexed unique per provider.
     */
    private function capture(WebhookEvent $event, PurchaseEventData $data): void
    {
        $plan = Plan::find($data->planId);
        $user = User::find($data->userId);

        if ($plan === null || $user === null) {
            $event->forceFill([
                'processed_at' => now(),
                'error' => "Unknown plan [{$data->planId}] or user [{$data->userId}].",
            ])->save();

            return;
        }

        // Trust the plan's price, not the amount the webhook reports. The
        // payment link's `notes` are ours; its amount came back through a
        // client-visible URL.
        Purchase::firstOrCreate(
            ['provider' => $this->gateway->name(), 'provider_ref' => $data->providerPaymentId],
            [
                'user_id' => $user->id,
                'product_id' => $plan->product_id,
                'amount_minor' => $plan->price_minor,
                'currency' => $plan->currency,
            ],
        );

        $event->forceFill(['processed_at' => now()])->save();
    }

    private function refund(WebhookEvent $event, PurchaseEventData $data): void
    {
        $purchase = Purchase::query()
            ->where('provider', $this->gateway->name())
            ->where('provider_ref', $data->providerPaymentId)
            ->lockForUpdate()
            ->first();

        if ($purchase === null) {
            $event->forceFill([
                'processed_at' => now(),
                'error' => "No purchase for payment [{$data->providerPaymentId}].",
            ])->save();

            return;
        }

        // Refunding twice must not move the timestamp: the learner lost access
        // when the first refund landed, and the audit trail should say so.
        if ($purchase->refunded_at === null) {
            $purchase->update(['refunded_at' => $data->occurredAt]);
        }

        $event->forceFill(['processed_at' => now()])->save();
    }
}
