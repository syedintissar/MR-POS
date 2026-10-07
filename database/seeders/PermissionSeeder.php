<?php

namespace Database\Seeders;

use App\Support\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Safe to run on every deploy: creates missing permissions and roles, and never takes a permission away
 * from a role the administrator has customised. New permissions are given to the default roles that should have them.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $newPermissions = [];
        foreach (PermissionCatalogue::all() as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($permission->wasRecentlyCreated) {
                $newPermissions[] = $name;
            }
        }

        foreach (PermissionCatalogue::defaultRoles() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            } else {
                $toAdd = array_values(array_intersect($permissions, $newPermissions));
                if ($toAdd !== []) {
                    $role->givePermissionTo($toAdd);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
