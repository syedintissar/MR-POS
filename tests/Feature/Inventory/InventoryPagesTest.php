<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class InventoryPagesTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private function adjust(array $over = []): array
    {
        return array_merge(['reason' => 'count_correction', 'adjustment_date' => now()->toDateString(), 'notes' => 'test'], $over);
    }

    public function test_the_stock_overview_counts_low_and_out_of_stock_and_values_the_stock(): void
    {
        $this->as('Manager');
        $this->stocked('2', '100', ['name' => 'Almost gone', 'min_stock_level' => '5']);
        $this->stocked('50', '10', ['name' => 'Plenty']);
        Product::factory()->create(['name' => 'Nothing left']);
        Product::factory()->service()->create(['name' => 'Labour']);
        Product::factory()->inactive()->create(['name' => 'Hidden one']);

        $html = $this->get('/inventory')->assertOk()->getContent();

        $this->assertStringContainsString('Almost gone', $html);
        $this->assertStringNotContainsString('Labour', $html, 'Services have no stock.');
        $this->assertStringNotContainsString('Hidden one', $html, 'Inactive products are not on the shelf list.');
        $this->assertStringContainsString('Rs. 700.00', $html, '2×100 + 50×10 = 700');
        $this->get('/inventory?level=low')->assertSee('Almost gone')->assertDontSee('Plenty')->assertDontSee('Nothing left');
        $this->get('/inventory?level=out')->assertSee('Nothing left')->assertDontSee('Almost gone');
        $this->get('/inventory?level=ok')->assertSee('Plenty')->assertDontSee('Almost gone');
    }

    public function test_products_below_zero_are_flagged_when_negative_stock_is_allowed(): void
    {
        app(SettingsService::class)->set('inventory.allow_negative_stock', true);
        $this->as('Manager');
        $p = $this->stocked('1', '10', ['name' => 'Oversold']);
        $this->inventory()->move($p, '-3', 'sale');

        $this->get('/inventory?level=negative')->assertSee('Oversold')->assertSee('Below zero');
    }

    public function test_the_stock_overview_filters_by_category_and_search(): void
    {
        $this->as('Manager');
        $this->stocked('3', '1', ['name' => 'A toner', 'category_id' => Category::query()->where('name', 'Toners')->value('id')]);
        $this->stocked('3', '1', ['name' => 'A cable', 'category_id' => Category::query()->where('name', 'Cables')->value('id')]);

        $this->get('/inventory?category='.Category::query()->where('name', 'Toners')->value('id'))->assertSee('A toner')->assertDontSee('A cable');
        $this->get('/inventory?q=cable')->assertSee('A cable')->assertDontSee('A toner');
    }

    public function test_the_movement_ledger_filters_by_product_type_and_date(): void
    {
        $this->as('Manager');
        $a = $this->stocked('10', '5', ['name' => 'Alpha thing']);
        $b = $this->stocked('4', '5', ['name' => 'Beta thing']);
        $this->inventory()->move($a, '-2', 'sale');

        $this->get('/inventory/movements')->assertOk()->assertSee('Alpha thing')->assertSee('Beta thing');
        $this->get("/inventory/movements?product={$a->id}")->assertSee('Stock card:')->assertSee('Sale')->assertDontSee('Beta thing');
        $this->get('/inventory/movements?type=sale')->assertSee('Alpha thing')->assertDontSee('Beta thing');
        $this->get('/inventory/movements?from='.now()->addDay()->toDateString())->assertSee('No movements match');
        $this->get('/inventory/movements?to='.now()->toDateString())->assertSee('Beta thing');
        $this->assertNotNull($b->id);
    }

    public function test_the_stock_card_shows_a_continuous_running_balance(): void
    {
        $this->as('Manager');
        $p = $this->stocked('10', '5');
        $this->inventory()->move($p, '-3', 'sale');
        $this->inventory()->move($p, '1', 'sale_return', ['unit_cost' => '5']);

        $html = $this->get("/inventory/movements?product={$p->id}")->assertOk()->getContent();
        foreach (['Purchase received', 'Sale', 'Customer return', '+10', '-3', '+1'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame('8.000', $p->fresh()->current_stock);
    }

    public function test_posting_an_adjustment_through_the_form(): void
    {
        $this->as('Manager');
        $a = $this->stocked('10', '100', ['name' => 'Adjust me']);
        $b = Product::factory()->create(['name' => 'Second one']);

        $this->post('/inventory/adjustments', $this->adjust([
            'lines' => [
                ['product_id' => $a->id, 'direction' => 'out', 'quantity' => '3', 'notes' => 'broken'],
                ['product_id' => $b->id, 'direction' => 'in', 'quantity' => '4', 'unit_cost' => '55.5'],
            ],
        ]))->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'ADJ-000001'));

        $this->assertSame('7.000', $a->fresh()->current_stock);
        $this->assertSame('4.000', $b->fresh()->current_stock);
        $this->assertSame('55.5000', $b->fresh()->average_cost);

        $adj = StockAdjustment::query()->firstOrFail();
        $this->get("/inventory/adjustments/{$adj->id}")->assertOk()->assertSee('Adjust me')->assertSee('broken')->assertSee('ADJ-000001');
        $this->get('/inventory/adjustments')->assertOk()->assertSee('ADJ-000001')->assertSee('Posted');
        $this->get("/inventory/movements?product={$a->id}")->assertSee('ADJ-000001');
    }

    public function test_the_form_refuses_bad_input_with_clear_errors_and_keeps_nothing(): void
    {
        $this->as('Manager');
        $p = $this->stocked('5', '10');
        $service = Product::factory()->service()->create();
        $line = fn (array $o = []) => $o + ['product_id' => $p->id, 'direction' => 'in', 'quantity' => '1'];   // the override wins

        $this->post('/inventory/adjustments', $this->adjust(['lines' => []]))->assertSessionHasErrors('lines');
        $this->post('/inventory/adjustments', $this->adjust(['reason' => 'because', 'lines' => [$line()]]))->assertSessionHasErrors('reason');
        $this->post('/inventory/adjustments', $this->adjust(['adjustment_date' => now()->addDays(3)->toDateString(), 'lines' => [$line()]]))->assertSessionHasErrors('adjustment_date');
        $this->post('/inventory/adjustments', $this->adjust(['lines' => [$line(['quantity' => '0'])]]))->assertSessionHasErrors('lines.0.quantity');
        $this->post('/inventory/adjustments', $this->adjust(['lines' => [$line(['quantity' => '-2'])]]))->assertSessionHasErrors('lines.0.quantity');
        $this->post('/inventory/adjustments', $this->adjust(['lines' => [$line(['direction' => 'sideways'])]]))->assertSessionHasErrors('lines.0.direction');
        $this->post('/inventory/adjustments', $this->adjust(['lines' => [$line(['product_id' => $service->id])]]))->assertSessionHasErrors('lines.0.product_id');
        $this->post('/inventory/adjustments', $this->adjust(['lines' => [$line(['quantity' => '1.5'])]]))->assertSessionHasErrors('lines.0.quantity');   // pieces
        $this->post('/inventory/adjustments', $this->adjust(['reason' => 'opening', 'lines' => [$line(['direction' => 'out'])]]))->assertSessionHasErrors('lines');

        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame('5.000', $p->fresh()->current_stock);
    }

    public function test_removing_more_than_exists_is_refused_with_a_message_and_the_form_keeps_what_was_typed(): void
    {
        $this->as('Manager');
        $p = $this->stocked('2', '10', ['name' => 'Short stock']);
        $payload = $this->adjust(['lines' => [['product_id' => $p->id, 'direction' => 'out', 'quantity' => '9']]]);

        $this->from('/inventory/adjustments/create')->post('/inventory/adjustments', $payload)
            ->assertRedirect('/inventory/adjustments/create')->assertSessionHas('error', fn ($m) => str_contains($m, 'Not enough stock for "Short stock"'));

        $this->assertSame('2.000', $p->fresh()->current_stock);
        $this->assertSame(0, StockAdjustment::count());
        $this->get('/inventory/adjustments/create')->assertSee('Short stock');   // the line is still on the form
    }

    public function test_the_adjustment_form_opens_with_a_preselected_product(): void
    {
        $this->as('Manager');
        $p = $this->stocked('6', '1', ['name' => 'Preselected item']);

        $this->get("/inventory/adjustments/create?product={$p->id}")->assertOk()->assertSee('Preselected item');
    }

    public function test_voiding_needs_the_void_permission_a_password_and_a_reason(): void
    {
        $p = $this->stocked('10', '100');
        $this->as('Admin');
        $this->post('/inventory/adjustments', $this->adjust(['lines' => [['product_id' => $p->id, 'direction' => 'out', 'quantity' => '4']]]));
        $adj = StockAdjustment::query()->firstOrFail();
        $this->assertSame('6.000', $p->fresh()->current_stock);

        // a manager may adjust but may not void
        $this->as('Manager')->withSession($this->confirmed())->post("/inventory/adjustments/{$adj->id}/void", ['reason' => 'x'])->assertForbidden();

        $this->flushSession();   // forget the manager's password confirmation
        $this->as('Admin');
        $this->post("/inventory/adjustments/{$adj->id}/void", ['reason' => 'Mistake'])->assertRedirect(route('password.confirm'));   // needs the password first
        $this->assertSame('posted', $adj->fresh()->status);

        $this->withSession($this->confirmed());
        $this->post("/inventory/adjustments/{$adj->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/inventory/adjustments/{$adj->id}/void", ['reason' => 'Entered twice'])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('voided', $adj->fresh()->status);
        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->get("/inventory/adjustments/{$adj->id}")->assertSee('Cancelled')->assertSee('Entered twice');
        $this->post("/inventory/adjustments/{$adj->id}/void", ['reason' => 'again'])->assertSessionHas('error', fn ($m) => str_contains($m, 'already been cancelled'));
    }

    public function test_who_may_see_and_change_stock(): void
    {
        $p = $this->stocked('3', '1');
        $adj = StockAdjustment::query()->create(['adjustment_no' => 'ADJ-X', 'adjustment_date' => now(), 'reason' => 'found', 'status' => 'posted']);

        foreach (['Cashier', 'Technician'] as $role) {   // may view stock levels but never change them
            $this->as($role);
            $this->get('/inventory')->assertOk();
            $this->get('/inventory/movements')->assertOk();
            $this->get('/inventory/adjustments')->assertOk();
            $this->get("/inventory/adjustments/{$adj->id}")->assertOk();
            $this->get('/inventory/adjustments/create')->assertForbidden();
            $this->post('/inventory/adjustments', $this->adjust(['lines' => [['product_id' => $p->id, 'direction' => 'out', 'quantity' => '1']]]))->assertForbidden();
            $this->withSession($this->confirmed())->post("/inventory/adjustments/{$adj->id}/void", ['reason' => 'x'])->assertForbidden();
        }
        $this->assertSame('3.000', $p->fresh()->current_stock);

        $this->asWithOnly(['products.view'])->get('/inventory')->assertForbidden();
        $this->asWithOnly(['inventory.view', 'inventory.adjust'])->get('/inventory/adjustments/create')->assertOk();
    }

    public function test_the_dashboard_shows_stock_alerts_to_people_who_may_see_stock(): void
    {
        $this->stocked('1', '10', ['name' => 'Dashboard low item', 'min_stock_level' => '5']);
        Product::factory()->create(['name' => 'Dashboard empty item']);
        $this->stocked('90', '1', ['name' => 'Dashboard fine item']);

        foreach (['Manager', 'Cashier'] as $role) {
            $this->as($role)->get('/')->assertOk()->assertSee('Stock alerts')->assertSee('Dashboard low item')->assertSee('Dashboard empty item')->assertDontSee('Dashboard fine item');
        }
        $this->asWithOnly(['dashboard.view'])->get('/')->assertOk()->assertDontSee('Stock alerts');
    }

    public function test_the_integrity_check_passes_when_everything_adds_up_and_reports_drift_loudly(): void
    {
        $p = $this->stocked('10', '5', ['name' => 'Drifting item']);
        $this->artisan('pos:integrity:check')->expectsOutputToContain('Integrity check passed')->assertExitCode(0);

        DB::table('products')->where('id', $p->id)->update(['current_stock' => 77]);   // someone edits the cache directly

        $this->artisan('pos:integrity:check')->expectsOutputToContain('Drifting item')->assertExitCode(1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'integrity_problem']);

        $this->as('Manager')->get('/')->assertSee('Stock records do not add up')->assertSee('Drifting item');
        $this->as('Cashier')->get('/')->assertDontSee('Stock records do not add up');   // only people who can act on it see it
    }

    public function test_the_integrity_check_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('pos:integrity:check')->assertExitCode(0);
    }

    public function test_stock_movements_survive_the_deletion_of_nothing_and_users_stay_referenced(): void
    {
        $user = User::factory()->create();
        $p = $this->stocked('2', '1');
        $this->inventory()->move($p, '-1', 'sale', ['user' => $user]);

        $m = StockMovement::query()->where('user_id', $user->id)->firstOrFail();
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->delete();   // a user with history cannot be removed at the database level
        $this->assertNotNull($m);
    }
}
