<?php

namespace App\Services\Launch;

use App\Launch\LaunchRequest;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Just-in-time provisioning on first launch.
 *
 * **Never auto-links to an existing account by email.** Consider: a learner has
 * a personal B2C subscription under r.sharma@abcschool.edu. ABC's SIS is
 * compromised, or careless, or a rogue teacher edits a student record, and signs
 * a launch with `sub: "attacker-1", email: "r.sharma@abcschool.edu"`. Linking on
 * email hands the attacker that paid account, its progress and its scores.
 *
 * An email in a launch token is a claim by the client *about a third party*. It
 * has exactly the trust level of the client: enough to display, never enough to
 * authenticate. So a launch only ever creates or reuses a `client:{slug}`
 * identity, and the user it provisions has no email and no password.
 */
final class ProvisionClientUser
{
    public function handle(Client $client, LaunchRequest $launch): ClientUser
    {
        return DB::transaction(function () use ($client, $launch) {
            $clientUser = ClientUser::query()
                ->where('client_id', $client->id)
                ->where('external_user_id', $launch->externalUserId)
                ->lockForUpdate()
                ->first();

            if ($clientUser !== null) {
                return $this->refresh($clientUser, $launch);
            }

            $user = $this->provisionUser($launch);

            UserIdentity::create([
                'user_id' => $user->id,
                'provider' => $client->identityProvider(),
                'provider_uid' => $launch->externalUserId,
                'verified_at' => now(),
            ]);

            return ClientUser::create([
                'client_id' => $client->id,
                'external_user_id' => $launch->externalUserId,
                'user_id' => $user->id,
                'role' => $launch->role,
                'external_name' => $launch->externalName,
                'external_email' => $launch->externalEmail,   // stored, never trusted
                'status' => 'active',
                'last_seen_at' => now(),
            ]);
        });
    }

    private function provisionUser(LaunchRequest $launch): User
    {
        // No email, no password. A client-provisioned user cannot log in
        // directly, cannot reset a password, and cannot be phished into one.
        // The database enforces it (users_provisioned_has_no_password).
        return User::create([
            'name' => $launch->externalName ?? "Learner {$launch->externalUserId}",
            'kind' => User::KIND_CLIENT_PROVISIONED,
            'status' => 'active',
        ]);
    }

    /**
     * A returning user's role and name are refreshed from the launch — the SIS
     * is the source of truth for both. Their `status` is not: deactivation is an
     * administrative act, and a launch must not undo it.
     */
    private function refresh(ClientUser $clientUser, LaunchRequest $launch): ClientUser
    {
        $clientUser->forceFill(array_filter([
            'role' => $launch->role,
            'external_name' => $launch->externalName,
            'external_email' => $launch->externalEmail,
            'last_seen_at' => now(),
        ], fn ($value) => $value !== null))->save();

        return $clientUser;
    }
}
