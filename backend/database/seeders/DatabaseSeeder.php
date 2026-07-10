<?php

namespace Database\Seeders;

use App\Authorization\Roles;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * No WithoutModelEvents here.
 *
 * It suppresses the model events that spatie/laravel-permission uses to bust
 * its permission cache — syncPermissions then fails with PermissionDoesNotExist
 * — and it would silently disable the Auditable trait, which is exactly the
 * thing that must never be silently disabled.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (! app()->environment('local')) {
            return;
        }

        collect([
            Roles::ADMIN => 'admin@example.com',
            Roles::OPS => 'ops@example.com',
            Roles::CONTENT_AUTHOR => 'author@example.com',
            Roles::CONTENT_REVIEWER => 'reviewer@example.com',
        ])->each(fn (string $email, string $role) => User::factory()
            ->withRole($role)
            ->create(['name' => str($role)->headline()->toString(), 'email' => $email]));
    }
}
