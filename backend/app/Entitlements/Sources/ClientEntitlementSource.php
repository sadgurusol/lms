<?php

namespace App\Entitlements\Sources;

use App\Entitlements\Grant;
use App\Entitlements\GrantSource;
use App\Models\ClientEntitlement;
use App\Models\ClientUser;
use App\Models\User;

/**
 * ABC School's contract, resolved for one of their students.
 *
 * Ordered first. A student launched from ABC reads under ABC's contract even if
 * they also hold a personal subscription — because the activity that follows is
 * reported to ABC, and attribution must follow the session context rather than
 * the cheapest grant.
 *
 * Returns nothing for a B2C session (`$clientId === null`), so a personal login
 * never inherits their school's entitlements.
 */
final class ClientEntitlementSource implements GrantSource
{
    public function name(): string
    {
        return Grant::SOURCE_CLIENT;
    }

    public function grantsFor(User $user, ?string $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        $membership = ClientUser::query()
            ->where('client_id', $clientId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null || ! $membership->client->isActive()) {
            return [];
        }

        $grants = [];

        foreach (ClientEntitlement::where('client_id', $clientId)->live()->get() as $entitlement) {
            if (! $this->seatAllows($entitlement, $membership)) {
                continue;
            }

            $grants[$entitlement->product_id] = new Grant(
                source: Grant::SOURCE_CLIENT,
                clientId: $clientId,
                referenceId: $entitlement->id,
                expiresAt: $entitlement->ends_at,
            );
        }

        return $grants;
    }

    /**
     * Seats are **soft-enforced** except under the `assigned` model.
     *
     * A student locked out of their coursework because their school
     * under-purchased is a support escalation and a reputational problem, and
     * the school will pay anyway. Allow the read, flag the overage, invoice.
     * Hard-enforce only after `ends_at` passes — which `live()` already does.
     *
     * `assigned` is different: assignment is an explicit administrative act, so
     * the refusal has an honest message and points at someone who can fix it.
     */
    private function seatAllows(ClientEntitlement $entitlement, ClientUser $membership): bool
    {
        if ($entitlement->seat_model !== ClientEntitlement::ASSIGNED) {
            return true;
        }

        return $entitlement->seatAssignments()
            ->where('client_user_id', $membership->id)
            ->whereNull('released_at')
            ->exists();
    }
}
