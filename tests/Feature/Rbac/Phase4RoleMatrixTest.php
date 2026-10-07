<?php

namespace Tests\Feature\Rbac;

use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Every Phase 4 screen against every default role, exactly as documented in docs/RBAC.md. Cashiers and technicians must see nothing about purchase costs or supplier accounts. */
class Phase4RoleMatrixTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    /** [method, uri template, [role => allowed?]]. {s} supplier, {d} draft purchase, {b} received purchase, {pay} supplier payment, {m} payment method */
    public static function routes(): array
    {
        $managers = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => false];
        $adminOnly = ['Admin' => true, 'Manager' => false, 'Cashier' => false, 'Technician' => false];

        $rows = [
            ['GET', '/purchases', $managers], ['GET', '/purchases/create', $managers], ['GET', '/purchases/{b}', $managers], ['GET', '/purchases/{b}/print', $managers],
            ['GET', '/purchases/{d}/edit', $managers], ['GET', '/purchases/lookup?q=a', $managers],
            ['POST', '/purchases', $managers], ['PUT', '/purchases/{d}', $managers], ['DELETE', '/purchases/{d}', $managers],
            ['POST', '/purchases/{d}/receive', $managers], ['POST', '/purchases/{b}/void', $managers],
            ['GET', '/supplier-payments', $managers], ['GET', '/supplier-payments/create', $managers], ['GET', '/supplier-payments/{pay}', $managers],
            ['POST', '/supplier-payments', $managers], ['POST', '/supplier-payments/{pay}/allocate', $managers], ['POST', '/supplier-payments/{pay}/void', $managers],
            ['GET', '/suppliers/{s}', $managers], ['GET', '/suppliers/{s}/statement', $managers],
            ['POST', '/suppliers/{s}/adjust-balance', $adminOnly],
            ['GET', '/admin/settings/payment_methods', array_merge($adminOnly, ['Manager' => true])],
            ['POST', '/admin/payment-methods', $adminOnly], ['PUT', '/admin/payment-methods/{m}', $adminOnly],
            ['POST', '/admin/payment-methods/{m}/toggle', $adminOnly], ['DELETE', '/admin/payment-methods/{m}', $adminOnly],
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
        $maker = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $line = [['product_id' => $product->id, 'quantity' => '2', 'unit_cost' => '10']];
        $save = app(SavePurchaseDraft::class);
        $base = ['supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(), 'lines' => $line];
        $draft = $save->execute($base, $maker);
        $bill = app(ReceivePurchase::class)->execute($save->execute($base, $maker), $maker);
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
        $payment = app(PostSupplierPayment::class)->execute(['supplier_id' => $supplier->id, 'payment_method_id' => $cash->id, 'amount' => '5', 'allocation' => 'advance'], $maker);
        $uri = str_replace(['{s}', '{d}', '{b}', '{pay}', '{m}'], [$supplier->id, $draft->id, $bill->id, $payment->id, $cash->id], $uri);

        $response = $this->as($role)->withSession($this->confirmed())->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($allowed) {
            $this->assertNotSame(403, $response->getStatusCode(), "{$role} was wrongly blocked from {$method} {$uri}");
            $this->assertLessThan(500, $response->getStatusCode(), "{$method} {$uri} crashed for {$role}");
        } else {
            $response->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_the_login_page_for_every_phase_4_screen(): void
    {
        foreach (['/purchases', '/purchases/create', '/supplier-payments', '/supplier-payments/create', '/suppliers/1', '/admin/settings/payment_methods'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_cashiers_and_technicians_see_no_purchase_or_supplier_account_information_anywhere(): void
    {
        $maker = User::factory()->create();
        $supplier = Supplier::factory()->create(['name' => 'Secret Wholesale Ltd']);
        $product = Product::factory()->create(['name' => 'Costly Toner']);
        app(ReceivePurchase::class)->execute(app(SavePurchaseDraft::class)->execute([
            'supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => '3', 'unit_cost' => '777.77']],
        ], $maker), $maker);

        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role);
            $this->get('/')->assertOk()->assertDontSee('Owed to suppliers')->assertDontSee('777.77')->assertDontSee('Secret Wholesale');
            $this->get("/products/{$product->id}")->assertOk()->assertDontSee('777.77');
            $this->get('/products?q=Costly')->assertOk()->assertDontSee('777.77');
            $this->getJson('/products/lookup?q=Costly')->assertOk()->assertDontSee('777.77')->assertDontSee('last_cost');
            $this->get('/inventory/movements')->assertOk()->assertDontSee('777.77');
        }
    }

    public function test_the_phase_4_permissions_are_granted_exactly_as_documented(): void
    {
        $perms = ['purchases.view', 'purchases.create', 'purchases.receive', 'purchases.void', 'supplier_payments.view', 'supplier_payments.create', 'supplier_payments.void', 'suppliers.view', 'suppliers.adjust_balance'];

        foreach (['Cashier', 'Technician'] as $role) {
            $user = User::factory()->withRole($role)->create();
            foreach ($perms as $p) {
                $this->assertFalse($user->can($p), "{$role} must not have {$p}");
            }
        }

        $manager = User::factory()->withRole('Manager')->create();
        foreach (array_diff($perms, ['suppliers.adjust_balance']) as $p) {
            $this->assertTrue($manager->can($p), "Manager should have {$p}");
        }
        $this->assertFalse($manager->can('suppliers.adjust_balance'), 'Opening balances and corrections are for the administrator only.');
    }
}
