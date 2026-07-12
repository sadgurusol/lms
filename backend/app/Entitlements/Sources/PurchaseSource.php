<?php

namespace App\Entitlements\Sources;

use App\Entitlements\Grant;
use App\Entitlements\GrantSource;
use App\Models\Purchase;
use App\Models\User;

final class PurchaseSource implements GrantSource
{
    public function name(): string
    {
        return Grant::SOURCE_PURCHASE;
    }

    public function grantsFor(User $user, ?string $clientId): array
    {
        $grants = [];

        foreach (Purchase::where('user_id', $user->id)->entitling()->get() as $purchase) {
            $grants[$purchase->product_id] = new Grant(
                source: Grant::SOURCE_PURCHASE,
                referenceId: $purchase->id,
                expiresAt: null,        // a one-time buy does not lapse
            );
        }

        return $grants;
    }
}
