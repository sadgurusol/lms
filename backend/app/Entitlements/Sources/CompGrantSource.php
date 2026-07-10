<?php

namespace App\Entitlements\Sources;

use App\Entitlements\Grant;
use App\Entitlements\GrantSource;
use App\Models\CompGrant;
use App\Models\User;

final class CompGrantSource implements GrantSource
{
    public function name(): string
    {
        return Grant::SOURCE_COMP;
    }

    public function grantsFor(User $user, ?string $clientId): array
    {
        $grants = [];

        foreach (CompGrant::query()->where('user_id', $user->id)->active()->get() as $comp) {
            $grants[$comp->product_id] = new Grant(
                source: Grant::SOURCE_COMP,
                referenceId: $comp->id,
                expiresAt: $comp->ends_at,
            );
        }

        return $grants;
    }
}
