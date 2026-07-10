<?php

namespace Database\Seeders;

use App\Authorization\Permissions;
use App\Authorization\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Admin is created with no explicit permissions: the Gate::before bypass
        // in AppServiceProvider grants everything. Enumerating them here would
        // rot the moment someone adds a permission and forgets the seeder.
        Role::findOrCreate(Roles::ADMIN, 'web');

        foreach (Permissions::forRoles() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
