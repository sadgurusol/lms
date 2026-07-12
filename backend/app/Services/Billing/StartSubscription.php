<?php

namespace App\Services\Billing;

use App\Billing\PaymentGateway;
use App\Billing\SubscriptionIntent;
use App\Exceptions\PaymentsUnavailable;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opens a checkout. Grants nothing.
 *
 * Access arrives with the activation webhook, never here: a client that reaches
 * this endpoint has expressed intent to pay, not paid.
 */
final class StartSubscription
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @return array{subscription: Subscription, checkout_url: string} */
    public function handle(User $user, Plan $plan): array
    {
        // A one-time plan has no renewal, so no activation webhook will ever
        // arrive for it. Opening a subscription here would leave a `pending` row
        // that nothing can ever move, and a learner who paid and got nothing.
        if ($plan->interval === Plan::ONE_TIME) {
            throw new RuntimeException("Plan [{$plan->code}] is a one-time purchase. Use /me/purchases.");
        }

        if ($plan->status !== 'active') {
            throw new RuntimeException("Plan [{$plan->code}] is not on sale.");
        }

        $live = Subscription::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->entitling()
            ->exists();

        if ($live) {
            throw new RuntimeException('You already have an active subscription to this plan.');
        }

        if (! $this->gateway->configured()) {
            throw new PaymentsUnavailable('Payments are not set up yet. Please try again later.');
        }

        try {
            $intent = $this->gateway->createSubscription($user, $plan);
        } catch (RequestException|ConnectionException $e) {
            report($e);
            throw new PaymentsUnavailable('We could not reach the payment provider. Please try again shortly.');
        }

        return DB::transaction(fn () => [
            'subscription' => $this->record($user, $plan, $intent),
            'checkout_url' => $intent->checkoutUrl,
        ]);
    }

    private function record(User $user, Plan $plan, SubscriptionIntent $intent): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'provider' => $this->gateway->name(),
            'provider_sub_id' => $intent->providerSubscriptionId,
            'status' => Subscription::PENDING,
        ]);
    }
}
