<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * Owner decision: managers and admins see cost, profit and margin; cashiers and sales staff must NEVER see them.
 * Every screen that could leak a cost is checked from both sides, using a cost that is easy to spot in the page.
 */
class CostVisibilityTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private const COST = '7654.32';

    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = $this->stocked('10', self::COST, ['name' => 'Costly toner', 'sale_price' => '9999.00']);
    }

    /** pages that show a product or its stock, and what they would reveal */
    private function pages(): array
    {
        $adj = StockAdjustment::query()->create(['adjustment_no' => 'ADJ-TEST', 'adjustment_date' => now(), 'reason' => 'found', 'status' => 'posted']);
        $adj->items()->create(['product_id' => $this->p->id, 'quantity' => '1', 'unit_cost' => self::COST]);

        return [
            '/products', "/products/{$this->p->id}", '/inventory', '/inventory/movements', "/inventory/movements?product={$this->p->id}",
            '/inventory/adjustments', "/inventory/adjustments/{$adj->id}", '/', '/inventory/labels',
        ];
    }

    public function test_managers_and_admins_see_costs_and_margins(): void
    {
        foreach (['Manager', 'Admin'] as $role) {
            $this->as($role);
            $show = $this->get("/products/{$this->p->id}")->assertOk();
            $show->assertSee('7,654.32')->assertSee('Average cost')->assertSee('margin');
            $this->get('/products')->assertSee('Avg cost')->assertSee('7,654.32');
            $this->get('/inventory')->assertSee('Stock value')->assertSee('76,543.20');
            $this->get("/inventory/movements?product={$this->p->id}")->assertSee('Unit cost');
        }
    }

    public function test_cashiers_never_see_a_cost_anywhere(): void
    {
        $this->as('Cashier');

        foreach ($this->pages() as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (['7654', '7,654', 'Avg cost', 'Average cost', 'Stock value', 'Unit cost', 'Cost each', 'margin', 'Margin', 'profit'] as $leak) {
                $this->assertStringNotContainsString($leak, $html, "A cashier must not see \"{$leak}\" on {$url}");
            }
        }
    }

    public function test_technicians_never_see_a_cost_anywhere(): void
    {
        $this->as('Technician');

        foreach ($this->pages() as $url) {
            $response = $this->get($url);
            if ($response->getStatusCode() !== 200) {
                $this->assertSame(403, $response->getStatusCode());   // pages they may not open at all

                continue;
            }
            $html = $response->getContent();
            foreach (['7654', '7,654', 'Avg cost', 'Average cost', 'Stock value', 'Unit cost', 'Cost each', 'margin'] as $leak) {
                $this->assertStringNotContainsString($leak, $html, "A technician must not see \"{$leak}\" on {$url}");
            }
        }
    }

    public function test_the_sale_price_is_visible_to_cashiers_but_the_cost_is_not(): void
    {
        $this->as('Cashier')->get("/products/{$this->p->id}")->assertOk()->assertSee('9,999.00')->assertDontSee('7,654');
    }

    public function test_a_custom_role_gets_costs_only_if_it_is_given_that_permission(): void
    {
        $this->asWithOnly(['products.view', 'inventory.view'], 'Viewer');
        $this->get("/products/{$this->p->id}")->assertOk()->assertDontSee('7,654')->assertDontSee('Average cost');

        $this->asWithOnly(['products.view', 'inventory.view', 'products.view_cost'], 'Viewer');
        $this->get("/products/{$this->p->id}")->assertOk()->assertSee('7,654.32');
    }

    public function test_the_product_form_hides_the_average_cost_without_the_permission_and_shows_it_with_it(): void
    {
        $this->asWithOnly(['products.view', 'products.edit', 'products.edit_price']);
        $this->get("/products/{$this->p->id}/edit")->assertOk()->assertDontSee('Average cost')->assertDontSee('7,654');

        $this->as('Manager')->get("/products/{$this->p->id}/edit")->assertOk()->assertSee('Average cost')->assertSee('7,654.32');
    }

    public function test_the_adjustment_form_offers_a_cost_box_only_to_people_who_may_see_costs(): void
    {
        $this->asWithOnly(['products.view', 'inventory.view', 'inventory.adjust']);
        $this->get('/inventory/adjustments/create')->assertOk()->assertDontSee('Cost each');

        $this->as('Manager')->get('/inventory/adjustments/create')->assertOk()->assertSee('Cost each');
    }

    public function test_a_cost_forged_into_an_adjustment_by_someone_who_cannot_see_costs_is_ignored(): void
    {
        $this->asWithOnly(['products.view', 'inventory.view', 'inventory.adjust']);

        $this->post('/inventory/adjustments', [
            'reason' => 'found', 'adjustment_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->p->id, 'direction' => 'in', 'quantity' => '5', 'unit_cost' => '1.00']],
        ])->assertSessionHasNoErrors();

        $p = $this->p->fresh();
        $this->assertSame('15.000', $p->current_stock);
        $this->assertSame('7654.3200', $p->average_cost, 'The forged cost of 1.00 must not have moved the average.');
    }

    public function test_the_lookup_json_never_contains_cost_fields_for_anyone(): void
    {
        foreach (['Cashier', 'Manager', 'Admin'] as $role) {
            $this->as($role);
            $data = $this->getJson('/products/lookup?q=Costly')->assertOk()->json();
            $this->assertStringNotContainsString('7654', json_encode($data));
            foreach (array_keys($data[0]) as $key) {
                $this->assertStringNotContainsString('cost', $key, "The lookup must not expose a field called {$key}");
                $this->assertStringNotContainsString('price', $key);
            }
        }
    }

    public function test_the_import_template_contains_no_real_cost_data(): void
    {
        $this->as('Manager');
        $csv = $this->get('/products-import/template')->assertOk()->getContent();

        $this->assertStringNotContainsString('7654', $csv);
    }

    public function test_the_cashier_role_holds_none_of_the_cost_or_profit_permissions(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        $tech = User::factory()->withRole('Technician')->create();

        foreach (['products.view_cost', 'reports.profit', 'dashboard.financials'] as $perm) {
            $this->assertFalse($cashier->can($perm));
            $this->assertFalse($tech->can($perm));
        }
    }
}
