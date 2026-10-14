<?php

namespace Tests\Feature\Rbac;

use App\Actions\Expenses\SaveExpense;
use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\CreateSaleReturn;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\ReportCatalogue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * Every Phase 8 route against every default role, exactly as documented in docs/RBAC.md.
 * {e} an expense, {c} an expense category, {s} a sale, {sr} a sales return, {p} a purchase, {pr} a purchase return, {r} a report.
 */
class Phase8RoleMatrixTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    /** [method, uri template, [role => allowed?]] */
    public static function routes(): array
    {
        $all = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => true];
        $notTech = ['Admin' => true, 'Manager' => true, 'Cashier' => true, 'Technician' => false];
        $managers = ['Admin' => true, 'Manager' => true, 'Cashier' => false, 'Technician' => false];
        $nobody = ['Admin' => false, 'Manager' => false, 'Cashier' => false, 'Technician' => false];

        $rows = [
            // expenses: managers and administrators only (a cashier or technician cannot even look)
            ['GET', '/expenses', $managers], ['GET', '/expenses?q=a&status=all', $managers], ['GET', '/expenses/create', $managers], ['POST', '/expenses', $managers],
            ['GET', '/expenses/{e}', $managers], ['GET', '/expenses/{e}/attachment', $managers],
            ['GET', '/expenses/{e}/edit', $managers], ['PUT', '/expenses/{e}', $managers], ['POST', '/expenses/{e}/void', $managers],
            ['GET', '/expenses/categories', $managers], ['POST', '/expenses/categories', $managers], ['PUT', '/expenses/categories/{c}', $managers], ['POST', '/expenses/categories/{c}/toggle', $managers],

            // sales returns: a cashier may look and print a copy, a manager takes goods back and cancels returns
            ['GET', '/sale-returns', $notTech], ['GET', '/sale-returns?q=a&status=all', $notTech], ['GET', '/sale-returns/{sr}', $notTech],
            ['GET', '/sale-returns/create', $managers], ['GET', '/sale-returns/create?sale={s}', $managers], ['POST', '/sale-returns/sales/{s}', $managers],
            ['POST', '/sale-returns/{sr}/void', $managers],
            ['GET', '/sale-returns/{sr}/print', $nobody],   // the original receipt is a signed link only
            ['GET', '/sale-returns/{sr}/reprint', $notTech],

            // purchase returns: managers only
            ['GET', '/purchase-returns', $managers], ['GET', '/purchase-returns/{pr}', $managers], ['GET', '/purchase-returns/{pr}/print', $managers],
            ['GET', '/purchase-returns/create', $managers], ['GET', '/purchase-returns/create?purchase={p}', $managers],
            ['POST', '/purchase-returns/purchases/{p}', $managers], ['POST', '/purchase-returns/free', $managers], ['POST', '/purchase-returns/{pr}/void', $managers],

            // reports: managers only, and only they can export
            ['GET', '/reports', $managers],
            ['GET', '/reports/{r}', $managers], ['GET', '/reports/{r}/print', $managers],
            ['GET', '/reports/{r}/export/csv', $managers], ['GET', '/reports/{r}/export/xlsx', $managers], ['GET', '/reports/{r}/export/pdf', $managers],
            ['GET', '/reports/profit', $managers], ['GET', '/reports/profit/export/csv', $managers],

            // the dashboard is for everybody (what is on it follows the permissions: see DashboardTest)
            ['GET', '/', $all], ['GET', '/?preset=this_month', $all],
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
        $user = User::factory()->withRole($role)->create();

        $product = $this->stocked('20', '10', ['sale_price' => '100']);
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => '3']],
            'payments' => [['payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'amount' => '300']],
        ], $manager);
        $saleReturn = app(CreateSaleReturn::class)->execute($sale, [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '1', 'restock' => true]], [], 'x', $manager);

        $purchase = app(ReceivePurchase::class)->execute(app(SavePurchaseDraft::class)->execute([
            'supplier_id' => Supplier::factory()->create()->id, 'purchase_date' => now()->toDateString(), 'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '5', 'unit_cost' => '10']],
        ], $manager), $manager);
        $purchaseReturn = app(CreatePurchaseReturn::class)->execute(['purchase_id' => $purchase->id, 'lines' => [['purchase_item_id' => $purchase->items()->sole()->id, 'quantity' => '1']], 'reason' => 'x'], $manager);

        $category = ExpenseCategory::query()->where('name', 'Rent')->firstOrFail();
        $expense = app(SaveExpense::class)->execute([
            'expense_category_id' => $category->id, 'expense_date' => now()->toDateString(), 'amount' => '100',
            'payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'description' => 'Rent',
        ], $manager);

        $uri = str_replace(
            ['{e}', '{c}', '{sr}', '{s}', '{pr}', '{p}', '{r}'],
            [(string) $expense->id, (string) $category->id, (string) $saleReturn->id, (string) $sale->id, (string) $purchaseReturn->id, (string) $purchase->id, 'sales-summary'],
            $uri,
        );

        $response = $this->actingAs($user)->withSession($this->confirmed())->call($method, $uri, $method === 'GET' ? [] : ['_token' => csrf_token()]);

        if ($allowed) {
            $this->assertNotSame(403, $response->getStatusCode(), "{$role} was wrongly blocked from {$method} {$uri}");
            $this->assertLessThan(500, $response->getStatusCode(), "{$method} {$uri} crashed for {$role}");
        } else {
            $response->assertForbidden();
        }
    }

    public function test_every_report_is_closed_to_cashiers_and_technicians(): void
    {
        foreach (['Cashier', 'Technician'] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create());
            foreach (array_keys(ReportCatalogue::REPORTS) as $key) {
                $this->get("/reports/{$key}")->assertForbidden();
            }
        }
    }

    public function test_the_default_roles_hold_exactly_the_documented_phase_8_permissions(): void
    {
        $returns = ['sale_returns.view', 'sale_returns.create', 'sale_returns.refund', 'sale_returns.override_period', 'purchase_returns.view', 'purchase_returns.create'];
        $expenses = ['expenses.view', 'expenses.create', 'expenses.edit', 'expenses.void'];
        $reports = ['reports.sales', 'reports.inventory', 'reports.purchases', 'reports.customers', 'reports.suppliers', 'reports.service', 'reports.expenses', 'reports.profit', 'reports.export'];

        $can = fn (string $role, array $perms) => collect($perms)->mapWithKeys(fn ($p) => [$p => User::factory()->withRole($role)->create()->can($p)])->all();

        $this->assertSame(array_fill_keys([...$returns, ...$expenses, ...$reports], true), $can('Manager', [...$returns, ...$expenses, ...$reports]));
        $this->assertSame(array_fill_keys([...$returns, ...$expenses, ...$reports], true), $can('Admin', [...$returns, ...$expenses, ...$reports]));

        $cashier = $can('Cashier', [...$returns, ...$expenses, ...$reports]);
        $this->assertSame(['sale_returns.view'], array_keys(array_filter($cashier)), 'a cashier looks at returns; no expenses and nothing else of Phase 8');

        $this->assertSame([], array_keys(array_filter($can('Technician', [...$returns, ...$expenses, ...$reports]))), 'a technician has no Phase 8 permission at all');
    }

    public function test_guests_are_sent_to_the_login_page_for_every_phase_8_screen(): void
    {
        foreach (['/expenses', '/expenses/create', '/expenses/1', '/expenses/categories', '/sale-returns', '/sale-returns/create', '/sale-returns/1', '/purchase-returns',
            '/purchase-returns/create', '/purchase-returns/1', '/reports', '/reports/profit', '/reports/profit/print', '/reports/profit/export/csv'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        foreach (['/expenses', '/sale-returns/sales/1', '/purchase-returns/free', '/purchase-returns/purchases/1', '/sale-returns/1/void', '/purchase-returns/1/void'] as $uri) {
            $this->post($uri, [])->assertRedirect('/login');
        }
    }
}
