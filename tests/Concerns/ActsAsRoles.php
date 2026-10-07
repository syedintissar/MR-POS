<?php

namespace Tests\Concerns;

use App\Models\User;
use Spatie\Permission\Models\Role;

trait ActsAsRoles
{
    protected function as(string $role): static
    {
        return $this->actingAs(User::factory()->withRole($role)->create());
    }

    /** A user whose role holds exactly the listed permissions (to prove each permission is enforced on its own). */
    protected function asWithOnly(array $permissions, string $roleName = 'Limited'): static
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    protected function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }
}
