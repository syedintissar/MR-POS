<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshesSeededDatabase;

    private function admin(array $attrs = []): User
    {
        return User::factory()->withRole('Admin')->create($attrs);
    }

    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_admin_creates_a_user_who_must_change_the_password_and_it_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Ali Raza', 'username' => 'Ali.Raza', 'role' => 'Cashier', 'password' => 'temp-pass-123', 'is_active' => 1,
        ])->assertRedirect('/admin/users');

        $user = User::where('username', 'ali.raza')->firstOrFail();
        $this->assertTrue($user->hasRole('Cashier'));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(password_verify('temp-pass-123', $user->password));

        $row = DB::table('audit_logs')->where('event', 'user_created')->latest('id')->first();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertStringNotContainsString('temp-pass-123', (string) $row->new_values, 'The password must never reach the audit log.');
    }

    public function test_username_must_be_unique_and_well_formed(): void
    {
        $admin = $this->admin();
        User::factory()->create(['username' => 'taken']);

        $this->actingAs($admin)->post('/admin/users', ['name' => 'X', 'username' => 'taken', 'role' => 'Cashier', 'password' => 'temp-pass-123'])
            ->assertSessionHasErrors('username');
        $this->actingAs($admin)->post('/admin/users', ['name' => 'X', 'username' => 'has space', 'role' => 'Cashier', 'password' => 'temp-pass-123'])
            ->assertSessionHasErrors('username');
        $this->actingAs($admin)->post('/admin/users', ['name' => 'X', 'username' => 'okname', 'role' => 'Cashier', 'password' => 'short'])
            ->assertSessionHasErrors('password');
    }

    public function test_a_manager_cannot_create_users(): void
    {
        $manager = User::factory()->withRole('Manager')->create();

        $this->actingAs($manager)->post('/admin/users', ['name' => 'X', 'username' => 'sneaky', 'role' => 'Admin', 'password' => 'temp-pass-123'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'sneaky']);
    }

    public function test_only_an_admin_can_create_another_admin(): void
    {
        // A custom role that may manage users but is not Admin.
        $role = Role::create(['name' => 'HR', 'guard_name' => 'web']);
        $role->givePermissionTo(['users.view', 'users.manage']);
        $hr = User::factory()->create();
        $hr->assignRole('HR');

        $this->actingAs($hr)->post('/admin/users', ['name' => 'X', 'username' => 'newboss', 'role' => 'Admin', 'password' => 'temp-pass-123'])
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['username' => 'newboss']);
    }

    public function test_deactivating_a_user_signs_them_out_everywhere_and_is_audited(): void
    {
        $admin = $this->admin();
        $cashier = User::factory()->withRole('Cashier')->create();
        DB::table('sessions')->insert(['id' => 'cashier-session', 'user_id' => $cashier->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->put("/admin/users/{$cashier->id}", ['name' => $cashier->name, 'role' => 'Cashier', 'is_active' => 0])
            ->assertRedirect('/admin/users');

        $this->assertFalse($cashier->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'cashier-session']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_deactivated', 'auditable_id' => $cashier->id]);
    }

    public function test_changing_a_role_is_audited_with_old_and_new_values(): void
    {
        $admin = $this->admin();
        $user = User::factory()->withRole('Cashier')->create();

        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => $user->name, 'role' => 'Manager', 'is_active' => 1]);

        $this->assertTrue($user->fresh()->hasRole('Manager'));
        $row = DB::table('audit_logs')->where('event', 'user_role_changed')->latest('id')->first();
        $this->assertSame('Cashier', json_decode($row->old_values, true)['role']);
        $this->assertSame('Manager', json_decode($row->new_values, true)['role']);
    }

    public function test_you_cannot_deactivate_yourself_or_remove_your_own_admin_role(): void
    {
        $admin = $this->admin();
        $this->admin(); // a second admin exists, so "last admin" is not what blocks this

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => $admin->name, 'role' => 'Admin', 'is_active' => 0])->assertStatus(422);
        $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => $admin->name, 'role' => 'Manager', 'is_active' => 1])->assertStatus(422);

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasRole('Admin'));
    }

    public function test_a_non_admin_cannot_modify_an_administrator_account(): void
    {
        $custom = Role::create(['name' => 'HR2', 'guard_name' => 'web']);
        $custom->givePermissionTo(['users.view', 'users.manage']);
        $hr = User::factory()->create();
        $hr->assignRole('HR2');
        $boss = $this->admin();

        $this->actingAs($hr)->put("/admin/users/{$boss->id}", ['name' => $boss->name, 'role' => 'Admin', 'is_active' => 0])->assertForbidden();
        $this->actingAs($hr)->put("/admin/users/{$boss->id}", ['name' => $boss->name, 'role' => 'Cashier', 'is_active' => 1])->assertForbidden();

        $boss->refresh();
        $this->assertTrue($boss->is_active);
        $this->assertTrue($boss->hasRole('Admin'));
    }

    public function test_an_admin_can_deactivate_another_admin_but_the_system_always_keeps_one_active_admin(): void
    {
        $a = $this->admin();
        $b = $this->admin();

        // Allowed: A remains.
        $this->actingAs($a)->put("/admin/users/{$b->id}", ['name' => $b->name, 'role' => 'Admin', 'is_active' => 0])->assertRedirect('/admin/users');
        $this->assertFalse($b->fresh()->is_active);

        // Now A is the only active admin and cannot lock themselves out in any way.
        $this->actingAs($a)->put("/admin/users/{$a->id}", ['name' => $a->name, 'role' => 'Admin', 'is_active' => 0])->assertStatus(422);
        $this->actingAs($a)->put("/admin/users/{$a->id}", ['name' => $a->name, 'role' => 'Manager', 'is_active' => 1])->assertStatus(422);

        $this->assertSame(1, User::query()->activeAdmins()->count());
    }

    public function test_reset_password_forces_a_change_signs_out_the_user_and_is_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->withRole('Cashier')->create(['password' => 'old-pass-12345']);
        DB::table('sessions')->insert(['id' => 'user-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->withSession($this->confirmed())
            ->post("/admin/users/{$user->id}/reset-password", ['password' => 'reset-pass-9999'])->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(password_verify('reset-pass-9999', $user->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'user-session']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_password_reset', 'auditable_id' => $user->id]);
    }

    public function test_role_changes_need_password_confirmation_and_are_audited_with_added_and_removed_permissions(): void
    {
        $admin = $this->admin();
        $role = Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);
        $role->givePermissionTo(['products.view', 'inventory.view']);

        // not confirmed → sent to the confirmation page, nothing changes
        $this->actingAs($admin)->put("/admin/roles/{$role->id}", ['name' => 'Supervisor', 'permissions' => ['products.view']])
            ->assertRedirect(route('password.confirm'));
        $this->assertTrue($role->fresh()->hasPermissionTo('inventory.view'));

        $this->withSession($this->confirmed())->put("/admin/roles/{$role->id}", ['name' => 'Supervisor', 'permissions' => ['products.view', 'sales.void']])
            ->assertRedirect('/admin/roles');

        $role = $role->fresh();
        $this->assertTrue($role->hasPermissionTo('sales.void'));
        $this->assertFalse($role->hasPermissionTo('inventory.view'));

        $row = DB::table('audit_logs')->where('event', 'role_permissions_changed')->latest('id')->first();
        $this->assertSame(['sales.void'], json_decode($row->new_values, true)['added']);
        $this->assertSame(['inventory.view'], json_decode($row->old_values, true)['removed']);
    }

    public function test_the_admin_role_cannot_be_edited_and_builtin_roles_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $adminRole = Role::findByName('Admin');
        $cashierRole = Role::findByName('Cashier');

        $this->actingAs($admin)->withSession($this->confirmed())->put("/admin/roles/{$adminRole->id}", ['permissions' => ['products.view']])->assertStatus(422);
        $this->delete("/admin/roles/{$cashierRole->id}")->assertStatus(422);

        $this->assertCount(91, $adminRole->fresh()->permissions);
        $this->assertNotNull(Role::findByName('Cashier'));
    }

    public function test_a_role_that_is_in_use_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $role = Role::create(['name' => 'Temp role', 'guard_name' => 'web']);
        User::factory()->create()->assignRole('Temp role');

        $this->actingAs($admin)->withSession($this->confirmed())->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');
        $this->assertNotNull(Role::query()->where('name', 'Temp role')->first());
    }

    public function test_unknown_permission_names_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withSession($this->confirmed())->post('/admin/roles', ['name' => 'Odd', 'permissions' => ['sales.do_anything']])
            ->assertSessionHasErrors('permissions.0');
        $this->assertDatabaseMissing('roles', ['name' => 'Odd']);
    }
}
