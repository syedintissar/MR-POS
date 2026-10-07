<?php

namespace Tests\Feature\Rbac;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Every new Phase 3 screen against every default role, exactly as documented in docs/RBAC.md. */
class Phase3RoleMatrixTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    /** [method, uri template, [role => allowed?]]. {p} product, {s} supplier, {a} adjustment */
    public static function routes(): array
    {
        $all = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => true];
        $managers = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => false];
        $adminOnly = ['Admin' => true, 'Manager' => false, 'Cashier' => false, 'Technician' => false];

        $rows = [
            ['GET', '/products', $all], ['GET', '/products/{p}', $all], ['GET', '/products/{p}/image', $all], ['GET', '/products/lookup?q=a', $all],
            ['GET', '/products/create', $managers], ['GET', '/products/{p}/edit', $managers], ['POST', '/products/{p}/toggle', $managers],
            ['GET', '/products-import', $managers], ['GET', '/products-import/template', $managers],
            ['GET', '/catalog/categories', $all], ['GET', '/catalog/brands', $all], ['GET', '/catalog/units', $all],
            ['POST', '/catalog/brands', $managers],
            ['GET', '/inventory', $all], ['GET', '/inventory/movements', $all], ['GET', '/inventory/adjustments', $all], ['GET', '/inventory/adjustments/{a}', $all],
            ['GET', '/inventory/adjustments/create', $managers], ['POST', '/inventory/adjustments', $managers],
            ['POST', '/inventory/adjustments/{a}/void', $adminOnly],
            ['GET', '/inventory/labels', $all],
            ['GET', '/suppliers', $managers], ['GET', '/suppliers/create', $managers], ['GET', '/suppliers/{s}/edit', $managers],
            ['DELETE', '/suppliers/{s}', $adminOnly],
        ];

        $cases = [];
        foreach ($rows as [$method, $uri, $expect]) {
            foreach ($expect as $role => $allowed) {
                $cases["$role $method $uri"] = [$role, $method, $uri, $allowed];
            }
        }

        return $cases;
    }

    #[DataProvider('routes')]
    public function test_route_access_matches_the_documented_matrix(string $role, string $method, string $uri, bool $allowed): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();
        $adjustment = StockAdjustment::query()->create(['adjustment_no' => 'ADJ-M', 'adjustment_date' => now(), 'reason' => 'found', 'status' => 'posted']);
        $uri = str_replace(['{p}', '{s}', '{a}'], [$product->id, $supplier->id, $adjustment->id], $uri);

        $response = $this->as($role)->withSession($this->confirmed())->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($allowed) {
            $this->assertNotSame(403, $response->getStatusCode(), "{$role} was wrongly blocked from {$method} {$uri}");
            $this->assertLessThan(500, $response->getStatusCode(), "{$method} {$uri} crashed for {$role}");
        } else {
            $response->assertForbidden();
        }
    }

    public function test_guests_cannot_reach_any_phase_3_page(): void
    {
        foreach (['/products', '/products/create', '/catalog', '/inventory', '/inventory/movements', '/inventory/adjustments', '/inventory/labels', '/suppliers', '/products-import'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_every_phase_3_permission_exists_and_is_enforced_individually(): void
    {
        $product = Product::factory()->create();

        // one permission at a time: it opens exactly its own door
        $this->asWithOnly(['products.create'])->get('/products/create')->assertOk();
        $this->asWithOnly(['products.create'])->get("/products/{$product->id}/edit")->assertForbidden();
        $this->asWithOnly(['products.edit'])->get("/products/{$product->id}/edit")->assertOk();
        $this->asWithOnly(['products.edit'])->get('/products')->assertForbidden();
        $this->asWithOnly(['products.import'])->get('/products-import')->assertOk();
        $this->asWithOnly(['catalog.manage'])->post('/catalog/brands', ['name' => 'Solo brand'])->assertRedirect();
        $this->asWithOnly(['inventory.adjust'])->get('/inventory/adjustments/create')->assertOk();
        $this->asWithOnly(['inventory.adjust'])->get('/inventory')->assertForbidden();
        $this->asWithOnly(['suppliers.create'])->get('/suppliers/create')->assertOk();
        $this->asWithOnly(['suppliers.view'])->get('/suppliers')->assertOk();
    }
}
