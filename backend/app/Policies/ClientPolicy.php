<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\Client;
use App\Models\User;

/**
 * Ops manages B2B clients. Admins bypass via Gate::before.
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::CLIENT_VIEW);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can(Permissions::CLIENT_VIEW);
    }

    public function manage(User $user, Client $client): bool
    {
        return $user->can(Permissions::CLIENT_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::CLIENT_MANAGE);
    }
}
