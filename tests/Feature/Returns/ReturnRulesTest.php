<?php

namespace Tests\Feature\Returns;

use App\Actions\Sales\VoidSaleReturn;
use App\Models\AuditLog;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Reports\ProfitService;
use App\Services\SettingsService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\Concerns\ReturnHelpers;
use Tests\TestCase;

/** The owner's rules for customer returns: a return period with a manager's exception, and an optional restocking fee. */
class ReturnRulesTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;
    use ReturnHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeManager();
    }

    private function age(int $days, $sale): void
    {
        DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subDays($days)->setTime(10, 0)]);
    }

    // ---------------------------------------------------------------- the return period

    public function test_the_return_period_is_seven_days_unless_the_administrator_changes_it(): void
    {
        $this->assertSame(7, (int) app(SettingsService::class)->get('sales.return_days'));
        $sale = $this->sell([[$this->item('150'), '10']]);

        $this->age(7, $sale);
        $this->assertSame('150.00', $this->giveBack($sale, [0 => ['quantity' => '1']])->total, 'the seventh day is still inside the period');

        $this->age(8, $sale);
        try {
            $this->giveBack($sale, [0 => ['quantity' => '1']]);
            $this->fail('A return was accepted on day eight without a reason.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Write the reason', $e->getMessage());
        }

        app(SettingsService::class)->set('sales.return_days', 30);
        $this->assertSame('150.00', $this->giveBack($sale, [0 => ['quantity' => '1']])->total, 'the new period applies at once');
        $this->age(31, $sale);
        $this->expectException(DomainException::class);
        $this->giveBack($sale, [0 => ['quantity' => '1']]);
    }

    public function test_zero_days_means_no_limit(): void
    {
        app(SettingsService::class)->set('sales.return_days', 0);
        $sale = $this->sell([[$this->item('150'), '3']]);
        $this->age(900, $sale);

        $this->assertSame('150.00', $this->giveBack($sale, [0 => ['quantity' => '1']])->total);
        $this->assertSame(0, AuditLog::query()->where('event', 'sale_return_period_exception')->count());
    }

    public function test_after_the_period_a_manager_can_allow_a_return_with_a_reason_that_is_audited(): void
    {
        $sale = $this->sell([[$this->item('150'), '3']]);
        $this->age(20, $sale);

        foreach (['', '  ', 'ok'] as $bad) {
            try {
                $this->giveBack($sale, [0 => ['quantity' => '1']], ['exception_reason' => $bad]);
                $this->fail("Accepted the reason [{$bad}]");
            } catch (DomainException $e) {
                $this->assertStringContainsString('reason', $e->getMessage());
            }
        }
        $this->assertSame(0, SaleReturn::query()->count());

        $return = $this->giveBack($sale, [0 => ['quantity' => '1']], ['exception_reason' => 'Faulty on delivery, customer was abroad']);

        $this->assertSame('Faulty on delivery, customer was abroad', $return->period_exception_reason);
        $log = AuditLog::query()->where('event', 'sale_return_period_exception')->sole();
        $this->assertSame($return->id, $log->auditable_id);
        $this->assertSame(7, $log->new_values['return_period_days']);
        $this->assertSame('Faulty on delivery, customer was abroad', $log->new_values['reason']);
        $this->assertStringContainsString('allowed after the 7-day return period', $log->description);

        // inside the period nothing is recorded as an exception
        $fresh = $this->sell([[$this->item('150'), '3']]);
        $this->assertNull($this->giveBack($fresh, [0 => ['quantity' => '1']], ['exception_reason' => 'not needed'])->period_exception_reason);
    }

    public function test_someone_without_the_permission_cannot_pass_the_period_even_with_a_reason(): void
    {
        $sale = $this->sell([[$this->item('150'), '3']]);
        $this->age(20, $sale);
        $this->asWithOnly(['sale_returns.create', 'sale_returns.refund']);

        try {
            $this->giveBack($sale, [0 => ['quantity' => '1']], ['exception_reason' => 'I say so'], 'Faulty', auth()->user());
            $this->fail('The period was passed without the permission.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('A manager can allow an exception', $e->getMessage());
        }
        $this->assertSame(0, SaleReturn::query()->count());
    }

    public function test_the_return_page_asks_for_the_reason_or_says_to_ask_a_manager(): void
    {
        $sale = $this->sell([[$this->item('150'), '3']]);
        $this->age(12, $sale);

        $this->as('Manager');
        $this->get("/sale-returns/create?sale={$sale->id}")->assertOk()->assertSee('12 days old')->assertSee('Why is this return allowed after 7 days?');
        $this->post("/sale-returns/sales/{$sale->id}", ['reason' => 'Faulty', 'lines' => [$sale->items()->sole()->id => ['quantity' => '1']]])->assertSessionHas('error');
        $this->assertSame(0, SaleReturn::query()->count());
        $this->post("/sale-returns/sales/{$sale->id}", ['reason' => 'Faulty', 'exception_reason' => 'Customer was abroad', 'lines' => [$sale->items()->sole()->id => ['quantity' => '1']]])->assertSessionHasNoErrors();
        $return = SaleReturn::query()->sole();
        $this->get("/sale-returns/{$return->id}")->assertOk()->assertSee('Allowed after the return period')->assertSee('Customer was abroad');

        $this->asWithOnly(['sale_returns.view', 'sale_returns.create']);
        $this->get("/sale-returns/create?sale={$sale->id}")->assertOk()->assertSee('Ask a manager to allow this return')->assertSee('The return period has passed: ask a manager.')->assertDontSee('Why is this return allowed');
    }

    public function test_a_cashier_who_was_given_the_right_to_take_goods_back_is_still_held_to_the_period(): void
    {
        $this->assertFalse(User::factory()->withRole('Cashier')->create()->can('sale_returns.override_period'));
        $this->assertTrue(User::factory()->withRole('Manager')->create()->can('sale_returns.override_period'));
        $this->assertTrue(User::factory()->withRole('Admin')->create()->can('sale_returns.override_period'));
    }

    // ---------------------------------------------------------------- the restocking fee

    public function test_the_fee_is_off_by_default(): void
    {
        $this->assertFalse(app(SettingsService::class)->bool('sales.restocking_fee_enabled'));
        $return = $this->giveBack($this->sell([[$this->item('150'), '2']]), [0 => ['quantity' => '1']]);

        $this->assertSame('0.00', $return->restocking_fee);
        $this->assertSame('150.00', $return->refund_amount);
    }

    public function test_a_fee_keeps_a_share_of_the_value_without_tax_and_the_customer_gets_the_rest(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('sales.restocking_fee_enabled', true);
        $settings->set('sales.restocking_fee_percent', '10');
        $sale = $this->sell([[$this->item('150'), '2']]);

        $return = $this->giveBack($sale, [0 => ['quantity' => '2']]);

        $this->assertSame('300.00', $return->total, 'the value of the goods');
        $this->assertSame('30.00', $return->restocking_fee);
        $this->assertSame('270.00', $return->refund_amount, 'what the customer gets back');
        $this->assertSame('270.00', $sale->fresh()->returned_amount);
        $this->assertAllBooksAgree();
    }

    public function test_the_fee_comes_off_what_a_named_customer_is_credited_and_the_account_stays_exact(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('sales.restocking_fee_enabled', true);
        $settings->set('sales.restocking_fee_percent', '10');
        $c = $this->named();
        $sale = $this->sell([[$this->item('150'), '2']], [], $c);   // 300 on account

        $return = $this->giveBack($sale, [0 => ['quantity' => '1']]);   // 150 value, 15 fee, 135 credited

        $this->assertSame(['150.00', '15.00', '135.00', '0.00'], [$return->total, $return->restocking_fee, $return->applied_to_due, $return->refund_amount]);
        $this->assertSame('165.00', $this->customerBalance($c), '300 owed less the 135 credited: the fee is not forgiven');
        $this->assertSame('165.00', $sale->fresh()->due_amount);
        $entry = $c->fresh()->ledgerEntries()->where('entry_type', 'sale_return')->sole();
        $this->assertSame('135.00', $entry->credit);
        $this->assertAllBooksAgree();

        app(VoidSaleReturn::class)->execute($return, 'Mistake', $this->manager);
        $this->assertSame('300.00', $this->customerBalance($c), 'cancelling puts back exactly what was credited');
        $this->assertSame('300.00', $sale->fresh()->due_amount);
        $this->assertSame('0.00', $sale->fresh()->returned_amount);
        $this->assertAllBooksAgree();
    }

    public function test_the_fee_can_be_waived_and_that_is_audited(): void
    {
        app(SettingsService::class)->set('sales.restocking_fee_enabled', true);
        $sale = $this->sell([[$this->item('150'), '2']]);

        $return = $this->giveBack($sale, [0 => ['quantity' => '1']], ['waive_fee' => true]);

        $this->assertSame('0.00', $return->restocking_fee);
        $this->assertSame('150.00', $return->refund_amount);
        $this->assertTrue(AuditLog::query()->where('event', 'sale_return_created')->sole()->new_values['fee_waived']);
    }

    public function test_the_fee_is_revenue_the_shop_kept_so_profit_counts_it(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('sales.restocking_fee_enabled', true);
        $settings->set('sales.restocking_fee_percent', '10');
        $sale = $this->sell([[$this->item('150', '50', '100'), '2']]);   // sold 300, cost 200
        $this->giveBack($sale, [0 => ['quantity' => '1']]);                // 150 comes back, the shop keeps 15, the unit goes back on the shelf

        $p = app(ProfitService::class)->summary(now()->toDateString(), now()->toDateString());

        $this->assertSame('135.00', $p['sales_returns'], 'only what was really given back comes off the sales');
        $this->assertSame('165.00', $p['sales_net']);
        $this->assertSame('100.00', $p['cogs_net']);
        $this->assertSame('65.00', $p['gross_profit']);
    }

    public function test_the_database_refuses_a_fee_larger_than_the_goods(): void
    {
        $return = $this->giveBack($this->sell([[$this->item('150'), '2']]), [0 => ['quantity' => '1']]);

        $this->expectException(QueryException::class);
        DB::table('sale_returns')->where('id', $return->id)->update(['restocking_fee' => '151.00']);
    }

    public function test_the_fee_and_the_period_are_settings_only_an_administrator_can_edit(): void
    {
        $fields = ['sales_max_discount_percent' => '5', 'sales_void_window_hours' => 24, 'sales_store_credit_enabled' => 1, 'sales_round_to' => '0', 'sales_return_days' => 14,
            'sales_restocking_fee_enabled' => 1, 'sales_restocking_fee_percent' => '12.5'];

        $this->as('Admin')->put('/admin/settings/sales', $fields)->assertRedirect();
        $s = app(SettingsService::class);
        $this->assertSame([14, true, 12.5], [(int) $s->get('sales.return_days'), $s->bool('sales.restocking_fee_enabled'), (float) $s->get('sales.restocking_fee_percent')]);

        foreach ([['sales_return_days' => -1], ['sales_return_days' => 'soon'], ['sales_restocking_fee_percent' => '51'], ['sales_restocking_fee_percent' => '-1']] as $bad) {
            $this->put('/admin/settings/sales', $bad + $fields)->assertSessionHasErrors();
        }
        $this->assertSame(14, (int) $s->get('sales.return_days'));

        $this->as('Manager')->put('/admin/settings/sales', ['sales_return_days' => 99] + $fields)->assertForbidden();
        $this->assertSame(14, (int) app(SettingsService::class)->get('sales.return_days'));
    }
}
