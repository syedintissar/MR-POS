<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Support\PermissionCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The default roles must behave exactly as documented in docs/RBAC.md. */
class RoleMatrixTest extends TestCase
{
    use RefreshesSeededDatabase;

    /** route => [method, uri, [role => expected status]] ; status 302 = allowed (redirects after doing its work) */
    public static function routeMatrix(): array
    {
        $rows = [
            'dashboard' => ['GET', '/', ['Admin' => 200, 'Manager' => 200, 'Cashier' => 200, 'Technician' => 200]],
            'users list' => ['GET', '/admin/users', ['Admin' => 200, 'Manager' => 200, 'Cashier' => 403, 'Technician' => 403]],
            'new user form' => ['GET', '/admin/users/create', ['Admin' => 200, 'Manager' => 403, 'Cashier' => 403, 'Technician' => 403]],
            'roles list' => ['GET', '/admin/roles', ['Admin' => 200, 'Manager' => 403, 'Cashier' => 403, 'Technician' => 403]],
            'settings view' => ['GET', '/admin/settings', ['Admin' => 200, 'Manager' => 200, 'Cashier' => 403, 'Technician' => 403]],
            'settings save' => ['PUT', '/admin/settings/shop', ['Admin' => 302, 'Manager' => 403, 'Cashier' => 403, 'Technician' => 403]],
            'backups page' => ['GET', '/admin/backups', ['Admin' => 200, 'Manager' => 200, 'Cashier' => 403, 'Technician' => 403]],
            'retry copies (backups.create)' => ['POST', '/admin/backups/retry-transfers', ['Admin' => 302, 'Manager' => 302, 'Cashier' => 403, 'Technician' => 403]],
            'backup settings' => ['PUT', '/admin/backups/settings', ['Admin' => 302, 'Manager' => 403, 'Cashier' => 403, 'Technician' => 403]],
            'add backup destination' => ['POST', '/admin/backups/destinations', ['Admin' => 302, 'Manager' => 403, 'Cashier' => 403, 'Technician' => 403]],
            'audit log' => ['GET', '/admin/audit-logs', ['Admin' => 200, 'Manager' => 403, 'Cashier' => 403, 'Technician' => 403]],
        ];

        $cases = [];
        foreach ($rows as $name => [$method, $uri, $expect]) {
            foreach ($expect as $role => $status) {
                $cases["$role → $name"] = [$role, $method, $uri, $status];
            }
        }

        return $cases;
    }

    /** @dataProvider routeMatrix */
    #[DataProvider('routeMatrix')]
    public function test_route_access_matches_the_documented_matrix(string $role, string $method, string $uri, int $expected): void
    {
        $user = User::factory()->withRole($role)->create();

        // For "allowed" write routes an empty/invalid body is fine: we only prove the gate let the request through.
        $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($expected === 403) {
            $response->assertForbidden();
        } elseif ($expected === 200) {
            $response->assertOk();
        } else {
            $this->assertNotSame(403, $response->getStatusCode(), "$role was wrongly blocked from $method $uri");
            $this->assertContains($response->getStatusCode(), [302, 422], "$role should have reached $method $uri");
        }
    }

    public function test_guests_cannot_reach_any_admin_page(): void
    {
        foreach (['/admin/users', '/admin/roles', '/admin/settings', '/admin/backups', '/admin/audit-logs'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_cashier_and_technician_have_no_cost_or_profit_permission(): void
    {
        foreach ([PermissionCatalogue::CASHIER, PermissionCatalogue::TECHNICIAN] as $roleName) {
            $user = User::factory()->withRole($roleName)->create();

            foreach (PermissionCatalogue::COST_PROFIT as $permission) {
                $this->assertFalse($user->can($permission), "$roleName must not have $permission");
            }
        }
    }

    public function test_manager_and_admin_can_see_cost_and_profit(): void
    {
        foreach ([PermissionCatalogue::MANAGER, PermissionCatalogue::ADMIN] as $roleName) {
            $user = User::factory()->withRole($roleName)->create();

            foreach (PermissionCatalogue::COST_PROFIT as $permission) {
                $this->assertTrue($user->can($permission), "$roleName should have $permission");
            }
        }
    }

    public function test_seeded_roles_have_exactly_the_documented_permissions(): void
    {
        foreach (PermissionCatalogue::defaultRoles() as $roleName => $expected) {
            $actual = Role::findByName($roleName)->permissions->pluck('name')->sort()->values()->all();
            sort($expected);

            $this->assertSame(array_values($expected), $actual, "$roleName permissions differ from docs/RBAC.md");
        }
    }

    public function test_every_catalogue_permission_exists_and_nothing_extra_does(): void
    {
        $db = Permission::query()->pluck('name')->sort()->values()->all();
        $catalogue = PermissionCatalogue::all();
        sort($catalogue);

        $this->assertSame($catalogue, $db);
        $this->assertCount(91, $catalogue);
    }

    public function test_admin_role_has_every_permission_and_manager_misses_the_documented_ones(): void
    {
        $admin = Role::findByName('Admin');
        $this->assertCount(count(PermissionCatalogue::all()), $admin->permissions);

        $manager = User::factory()->withRole('Manager')->create();
        foreach (['users.manage', 'roles.manage', 'settings.manage', 'backups.restore', 'backups.download', 'audit_logs.view', 'sales.below_min_price'] as $p) {
            $this->assertFalse($manager->can($p), "Manager must not have $p");
        }
        foreach (['sales.void', 'sale_returns.refund', 'products.edit_price', 'reports.profit', 'backups.create'] as $p) {
            $this->assertTrue($manager->can($p), "Manager should have $p");
        }
    }

    public function test_a_cashier_cannot_discount_or_change_prices_without_approval(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();

        $this->assertTrue($cashier->can('sales.discount'));
        $this->assertFalse($cashier->can('sales.discount_unlimited'));
        $this->assertFalse($cashier->can('sales.change_price'));
        $this->assertFalse($cashier->can('sales.void'));
        $this->assertFalse($cashier->can('sale_returns.refund'));
    }

    public function test_technician_is_limited_to_assigned_service_work(): void
    {
        $tech = User::factory()->withRole('Technician')->create();

        $this->assertTrue($tech->can('service_jobs.view_assigned'));
        $this->assertTrue($tech->can('service_jobs.manage_parts'));
        $this->assertFalse($tech->can('service_jobs.view_all'));
        $this->assertFalse($tech->can('pos.access'));
        $this->assertFalse($tech->can('sales.create'));
    }

    public function test_reseeding_does_not_undo_an_administrators_changes_to_a_role(): void
    {
        $cashier = Role::findByName('Cashier');
        $cashier->givePermissionTo('sale_returns.create');
        $cashier->revokePermissionTo('sales.reprint');

        $this->seed(PermissionSeeder::class);

        $cashier->refresh();
        $this->assertTrue($cashier->hasPermissionTo('sale_returns.create'));
        $this->assertFalse($cashier->hasPermissionTo('sales.reprint'));
        $this->assertSame(1, DB::table('roles')->where('name', 'Cashier')->count());
    }
}
