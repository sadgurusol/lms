<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\User;

/**
 * Ops manages the catalogue. Admins bypass via Gate::before.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PRODUCT_VIEW);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can(Permissions::PRODUCT_VIEW);
    }

    public function manage(User $user, Product $product): bool
    {
        return $user->can(Permissions::PRODUCT_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::PRODUCT_MANAGE);
    }
}
