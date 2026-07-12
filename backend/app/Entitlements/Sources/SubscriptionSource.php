<?php

namespace App\Entitlements\Sources;

use App\Entitlements\Grant;
use App\Entitlements\GrantSource;
use App\Models\Subscription;
use App\Models\User;

/**
 * Entitlement lives on the user, not the platform: a subscription bought on the
 * web unlocks the mobile app, and one bought through IAP unlocks the web.
 */
final class SubscriptionSource implements GrantSource
{
    public function name(): string
    {
        return Grant::SOURCE_SUBSCRIPTION;
    }

    public function grantsFor(User $user, ?string $clientId): array
    {
        $grants = [];

        $subscriptions = Subscription::query()
            ->with('plan:id,product_id')
            ->where('user_id', $user->id)
            ->entitling()
            ->get();

        foreach ($subscriptions as $subscription) {
            $grants[$subscription->plan->product_id] = new Grant(
                source: Grant::SOURCE_SUBSCRIPTION,
                referenceId: $subscription->id,
                // The resolver re-checks this on read, so a subscription lapsing
                // inside the cache window lapses on time.
                expiresAt: $subscription->current_period_end,
            );
        }

        return $grants;
    }
}
