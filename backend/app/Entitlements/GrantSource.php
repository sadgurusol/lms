<?php

namespace App\Entitlements;

use App\Models\User;

/**
 * One way a user can come to be entitled to a product.
 *
 * The resolver consults sources in a fixed order (see EntitlementResolver).
 * Adding subscriptions (M8) or client contracts (M9) means adding a class here
 * and one line to the ordered list — not a second branch in a controller.
 */
interface GrantSource
{
    public function name(): string;

    /**
     * The products this user holds through this source, right now.
     *
     * @param  string|null  $clientId  the session's client context, or null for B2C
     * @return array<string, Grant> keyed by product id
     */
    public function grantsFor(User $user, ?string $clientId): array;
}
