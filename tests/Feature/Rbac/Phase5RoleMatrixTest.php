<?php

namespace Tests\Feature\Rbac;

use App\Actions\Sales\CompleteSale;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * Every Phase 5 route against every default role, exactly as documented in docs/RBAC.md.
 * The sale used here was made by a manager, so a cashier asking for it is asking for somebody else's invoice.
 */
class Phase5RoleMatrixTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    /** [method, uri template, [role => allowed?]]. {s} = a sale made by a manager */
    public static function routes(): array
    {
        $staff = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => false];
        $managers = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => false];
        $nobody = ['Admin' => false, 'Manager' => false, 'Cashier' => false, 'Technician' => false];

        $rows = [
            ['GET', '/pos', $staff], ['GET', '/pos/products/search?q=a', $staff], ['GET', '/pos/products/quick', $staff],
            ['POST', '/pos/sales', $staff], ['POST', '/pos/approvals', $staff],
            ['GET', '/pos/held', $staff], ['POST', '/pos/held', $staff],
            ['GET', '/sales', $staff],
            ['GET', '/sales/{s}', $managers], ['GET', '/sales/{s}/reprint/thermal', $managers], ['GET', '/sales/{s}/reprint/a4', $managers], ['GET', '/sales/{s}/pdf', $managers],
            ['POST', '/sales/{s}/void', $managers],
            // the original print is a signed link only: without the signature nobody gets in, not even an administrator
            ['GET', '/sales/{s}/print/thermal', $nobody], ['GET', '/sales/{s}/print/a4', $nobody],
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
        $manager = User::factory()->withRole('Manager')->create();
        $product = $this->stocked('5', '100', ['sale_price' => '150']);
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $product->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'amount' => '150']],
        ], $manager);
        $uri = str_replace('{s}', (string) $sale->id, $uri);

        $response = $this->as($role)->withSession($this->confirmed())->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($allowed) {
            $this->assertNotSame(403, $response->getStatusCode(), "{$role} was wrongly blocked from {$method} {$uri}");
            $this->assertLessThan(500, $response->getStatusCode(), "{$method} {$uri} crashed for {$role}");
        } else {
            $response->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_the_login_page_for_every_phase_5_screen(): void
    {
        foreach (['/pos', '/pos/products/search?q=a', '/sales', '/sales/1', '/sales/1/reprint/thermal'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        $this->post('/pos/sales', [])->assertRedirect('/login');
    }

    public function test_the_phase_5_permissions_are_granted_exactly_as_documented(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        foreach (['pos.access', 'sales.create', 'sales.view_own', 'sales.hold', 'sales.reprint', 'sales.discount'] as $p) {
            $this->assertTrue($cashier->can($p), "A cashier should have {$p}");
        }
        foreach (['sales.view_all', 'sales.void', 'sales.change_price', 'sales.discount_unlimited', 'sales.below_min_price', 'sales.approve'] as $p) {
            $this->assertFalse($cashier->can($p), "A cashier must not have {$p}");
        }

        $manager = User::factory()->withRole('Manager')->create();
        foreach (['sales.view_all', 'sales.void', 'sales.change_price', 'sales.discount_unlimited', 'sales.approve'] as $p) {
            $this->assertTrue($manager->can($p), "A manager should have {$p}");
        }
        $this->assertFalse($manager->can('sales.below_min_price'), 'Selling below the lowest price is for the administrator.');

        $technician = User::factory()->withRole('Technician')->create();
        foreach (['pos.access', 'sales.create', 'sales.view_own', 'sales.view_all'] as $p) {
            $this->assertFalse($technician->can($p), "A technician must not have {$p}");
        }
    }

    public function test_cashiers_never_receive_cost_or_the_lowest_price_from_any_till_endpoint(): void
    {
        $manager = User::factory()->withRole('Manager')->create();
        $p = $this->stocked('5', '913.37', ['sale_price' => '1500', 'min_sale_price' => '1234.56', 'name' => 'Hidden cost item']);
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $p->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'amount' => '1500']],
        ], $manager);
        $cashier = User::factory()->withRole('Cashier')->create();
        $own = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $p->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'amount' => '1500']],
        ], $cashier);
        $this->actingAs($cashier);

        foreach (['/pos', '/pos/products/search?q=Hidden', '/pos/products/quick', "/sales/{$own->id}", "/sales/{$own->id}/reprint/thermal", "/sales/{$own->id}/reprint/a4", '/sales', '/'] as $uri) {
            $body = $this->get($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('913.37', $body, "cost leaked on {$uri}");
            $this->assertStringNotContainsString('1234.56', $body, "lowest price leaked on {$uri}");
        }
        $this->assertNotNull($sale);
    }
}
