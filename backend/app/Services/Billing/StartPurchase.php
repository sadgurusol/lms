<?php

namespace App\Services\Billing;

use App\Billing\PaymentGateway;
use App\Exceptions\PaymentsUnavailable;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Opens a payment link for a one-time plan. Grants nothing; the capture webhook
 * creates the Purchase.
 */
final class StartPurchase
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @return array{checkout_url: string, provider_link_id: string} */
    public function handle(User $user, Plan $plan): array
    {
        if ($plan->interval !== Plan::ONE_TIME) {
            throw new RuntimeException("Plan [{$plan->code}] is a subscription. Use /me/subscriptions.");
        }

        if ($plan->status !== 'active') {
            throw new RuntimeException("Plan [{$plan->code}] is not on sale.");
        }

        $owned = Purchase::where('user_id', $user->id)
            ->where('product_id', $plan->product_id)
            ->entitling()
            ->exists();

        if ($owned) {
            throw new RuntimeException('You already own this.');
        }

        if (! $this->gateway->configured()) {
            throw new PaymentsUnavailable('Payments are not set up yet. Please try again later.');
        }

        try {
            $intent = $this->gateway->createPaymentLink($user, $plan);
        } catch (RequestException|ConnectionException $e) {
            report($e);
            throw new PaymentsUnavailable('We could not reach the payment provider. Please try again shortly.');
        }

        return [
            'checkout_url' => $intent->checkoutUrl,
            'provider_link_id' => $intent->providerLinkId,
        ];
    }
}
