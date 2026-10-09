<?php

namespace Tests\Feature\Reports;

use App\Actions\Expenses\VoidExpense;
use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\VoidSaleReturn;
use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Models\Product;
use App\Services\Reports\ProfitService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsKnownShop;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * Profit worked out from a small shop whose every figure is known by hand, including the service jobs (charges, parts and the parts'
 * cost): if one of these numbers moves, the profit report is wrong.
 */
class ProfitTest extends TestCase
{
    use BuildsKnownShop;
    use RefreshesSeededDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStaff();
    }

    // ---------------------------------------------------------------- the known shop

    public function test_every_figure_of_a_known_shop_is_exact(): void
    {
        $this->dataset();

        $p = $this->profit()->summary($this->today(), $this->today());

        // product sales: 300 + 540 = 840, less 300 returned = 540; cost 600, less 100 back on the shelf = 500
        $this->assertSame('840.00', $p['sales_revenue']);
        $this->assertSame('300.00', $p['sales_returns']);
        $this->assertSame('540.00', $p['sales_net']);
        $this->assertSame('600.00', $p['cogs']);
        $this->assertSame('100.00', $p['cogs_returned']);
        $this->assertSame('500.00', $p['cogs_net']);
        $this->assertSame('40.00', $p['product_profit']);
        $this->assertSame('100.00', $p['returns_written_off'], 'the damaged unit: its cost is already in the cost of goods sold, and is shown on its own');

        // service: the delivered job billed 500 + 250 - 50 = 700 (charges 466.67, parts 233.33), the parts cost 100; the cancelled and the open ones count for nothing
        $this->assertSame('1', $p['service_jobs']);
        $this->assertSame('700.00', $p['service_revenue']);
        $this->assertSame('466.67', $p['service_labour']);
        $this->assertSame('233.33', $p['service_parts_revenue']);
        $this->assertSame('100.00', $p['service_parts_cost']);
        $this->assertSame('600.00', $p['service_profit']);

        $this->assertSame('1240.00', $p['total_revenue']);
        $this->assertSame('600.00', $p['total_cost']);
        $this->assertSame('640.00', $p['gross_profit']);
        $this->assertSame('51.6', $p['gross_margin']);

        $this->assertSame('300.00', $p['expenses']);
        $this->assertSame('200.00', $p['stock_losses'], 'two units lost at 100; the opening stock is not a loss');
        $this->assertSame('40.00', $p['service_written_off']);
        $this->assertSame('-20.00', $p['supplier_difference']);

        // 640 - (300 + 200 + 40) - 20
        $this->assertSame('80.00', $p['net_profit']);
    }

    public function test_service_charges_and_parts_both_show_in_the_profit(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);

        $this->deliveredJob('1000', [[$toner, '2']]);   // 1000 labour + 3000 toner, the toner cost 1200

        $p = $this->profit()->summary($this->today(), $this->today());
        $this->assertSame('4000.00', $p['service_revenue']);
        $this->assertSame('1000.00', $p['service_labour']);
        $this->assertSame('3000.00', $p['service_parts_revenue']);
        $this->assertSame('1200.00', $p['service_parts_cost']);
        $this->assertSame('2800.00', $p['service_profit']);
        $this->assertSame('2800.00', $p['gross_profit']);
        $this->assertSame('2800.00', $p['net_profit']);
        $this->assertSame('0.00', $p['sales_revenue']);
    }

    public function test_labour_only_jobs_are_pure_profit(): void
    {
        $this->deliveredJob('750');

        $p = $this->profit()->summary($this->today(), $this->today());
        $this->assertSame(['750.00', '750.00', '0.00', '0.00'], [$p['service_revenue'], $p['service_labour'], $p['service_parts_revenue'], $p['service_parts_cost']]);
        $this->assertSame('750.00', $p['gross_profit']);
        $this->assertSame('100.0', $p['gross_margin']);
    }

    public function test_a_job_counts_on_the_day_it_was_invoiced_and_only_when_delivered(): void
    {
        $job = $this->deliveredJob('500');
        $undelivered = app(ChangeServiceJobStatus::class)->execute(app(ChangeServiceJobStatus::class)->execute($this->openJob(), 'diagnosing', $this->manager), 'in_progress', $this->manager);
        app(AddServiceCharge::class)->execute($undelivered, ['description' => 'Repair', 'unit_price' => '900'], $this->manager);
        $yesterday = Carbon::yesterday();
        DB::table('service_jobs')->where('id', $job->id)->update(['invoiced_at' => $yesterday->copy()->setTime(15, 0)]);

        $this->assertSame('0.00', $this->profit()->summary($this->today(), $this->today())['service_revenue']);
        $this->assertSame('500.00', $this->profit()->summary($yesterday->toDateString(), $yesterday->toDateString())['service_revenue']);
        $this->assertSame('500.00', $this->profit()->summary($yesterday->toDateString(), $this->today())['service_revenue'], 'the unfinished 900 job is not counted');
    }

    // ---------------------------------------------------------------- what must not count

    public function test_cancelled_sales_returns_expenses_and_jobs_count_for_nothing(): void
    {
        $a = $this->stocked('50', '100', ['sale_price' => '150']);
        $sale = $this->sell([['product_id' => $a->id, 'quantity' => '2']]);
        $return = app(CreateSaleReturn::class)->execute($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']], [], 'x', $this->manager);
        app(VoidSaleReturn::class)->execute($return, 'Mistake', $this->manager);
        app(VoidExpense::class)->execute($this->expense('500'), 'Twice', $this->manager);

        $p = $this->profit()->summary($this->today(), $this->today());

        $this->assertSame(['300.00', '0.00', '200.00', '0.00', '0.00'], [$p['sales_revenue'], $p['sales_returns'], $p['cogs'], $p['cogs_returned'], $p['expenses']]);
        $this->assertSame('100.00', $p['net_profit']);

        app(VoidSaleReturn::class)->execute(app(CreateSaleReturn::class)->execute($sale->fresh(), [['sale_item_id' => $sale->items->first()->id, 'quantity' => '2']], [], 'x', $this->manager), 'Undo', $this->manager);
        $this->assertSame('100.00', $this->profit()->summary($this->today(), $this->today())['net_profit']);
    }

    public function test_tax_is_never_revenue(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('tax.enabled', true);
        $settings->set('tax.default_rate', '17');
        $a = $this->stocked('10', '600', ['sale_price' => '1000']);
        $sale = $this->sell([['product_id' => $a->id, 'quantity' => '1']]);
        $this->assertSame('1170.00', $sale->grand_total);
        $this->deliveredJob('1000');   // 1000 + 17% tax

        $p = $this->profit()->summary($this->today(), $this->today());

        $this->assertSame('1000.00', $p['sales_revenue']);
        $this->assertSame('1000.00', $p['service_revenue']);
        $this->assertSame('1400.00', $p['gross_profit'], '400 on the goods + 1000 for the service, tax left out of both');
    }

    public function test_a_later_change_of_cost_does_not_rewrite_past_profit(): void
    {
        $a = $this->stocked('10', '100', ['sale_price' => '150']);
        $this->sell([['product_id' => $a->id, 'quantity' => '2']]);
        $before = $this->profit()->summary($this->today(), $this->today())['gross_profit'];

        $this->inventory()->move($a, '10', 'purchase', ['unit_cost' => '400']);   // a dearer delivery moves the average cost

        $this->assertSame('100.00', $before);
        $this->assertSame($before, $this->profit()->summary($this->today(), $this->today())['gross_profit']);
    }

    public function test_the_period_is_inclusive_and_other_days_are_left_out(): void
    {
        $a = $this->stocked('50', '100', ['sale_price' => '150']);
        $old = $this->sell([['product_id' => $a->id, 'quantity' => '1']]);
        DB::table('sales')->where('id', $old->id)->update(['sale_date' => Carbon::now()->subDays(10)->setTime(23, 59, 59)]);
        $this->sell([['product_id' => $a->id, 'quantity' => '2']]);
        $this->expense('50', 'Electricity', Carbon::now()->subDays(10)->toDateString());
        $this->expense('70');

        $this->assertSame('300.00', $this->profit()->summary($this->today(), $this->today())['sales_revenue']);
        $this->assertSame('70.00', $this->profit()->summary($this->today(), $this->today())['expenses']);
        $ten = Carbon::now()->subDays(10)->toDateString();
        $this->assertSame('150.00', $this->profit()->summary($ten, $ten)['sales_revenue'], 'the last second of the day belongs to that day');
        $this->assertSame('50.00', $this->profit()->summary($ten, $ten)['expenses']);
        $this->assertSame('450.00', $this->profit()->summary($ten, $this->today())['sales_revenue']);
    }

    public function test_bad_periods_are_refused(): void
    {
        foreach ([['2026-02-01', '2026-01-01'], ['nonsense', '2026-01-01'], ['2000-01-01', '2026-01-01']] as [$from, $to]) {
            try {
                $this->profit()->summary($from, $to);
                $this->fail("Accepted {$from} to {$to}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->profit()->grouped($this->today(), $this->today(), 'fortnight');
    }

    // ---------------------------------------------------------------- grouping

    public function test_the_groups_add_up_to_the_same_figures_as_the_whole_period(): void
    {
        $this->dataset();
        // spread the activity over several days, weeks and months
        $d1 = Carbon::now()->subDays(40);
        $d2 = Carbon::now()->subDays(9);
        DB::table('sales')->where('status', 'completed')->orderBy('id')->limit(1)->update(['sale_date' => $d1->copy()->setTime(10, 0)]);
        DB::table('expenses')->where('status', 'posted')->update(['expense_date' => $d2->toDateString()]);
        DB::table('service_jobs')->where('status', 'delivered')->update(['invoiced_at' => $d2->copy()->setTime(12, 0)]);

        $from = Carbon::now()->subDays(60)->toDateString();
        $whole = $this->profit()->summary($from, $this->today());
        $this->assertNotSame('0.00', $whole['expenses']);

        foreach (array_keys(ProfitService::GROUPS) as $group) {
            $rows = $this->profit()->grouped($from, $this->today(), $group);
            foreach (ProfitService::FIGURES as $figure) {
                $sum = $figure === 'service_jobs'
                    ? (string) array_sum(array_map(fn ($r) => (int) $r[$figure], $rows))
                    : array_reduce($rows, fn ($c, $r) => bcadd($c, $r[$figure], 2), '0.00');
                $this->assertSame($whole[$figure], $sum, "{$figure} by {$group}");
            }
            $this->assertSame($whole['net_profit'], array_reduce($rows, fn ($c, $r) => bcadd($c, $r['net_profit'], 2), '0.00'), "net profit by {$group}");
        }
    }

    public function test_days_without_activity_are_listed_and_the_edges_follow_the_period(): void
    {
        $a = $this->stocked('50', '100', ['sale_price' => '150']);
        $this->sell([['product_id' => $a->id, 'quantity' => '1']]);

        $days = $this->profit()->grouped(Carbon::now()->subDays(2)->toDateString(), $this->today(), 'day');
        $this->assertCount(3, $days);
        $this->assertSame(['0.00', '0.00', '50.00'], array_column($days, 'gross_profit'));

        $weeks = $this->profit()->grouped('2026-10-05', '2026-10-18', 'week');   // two whole weeks, Monday to Sunday
        $this->assertSame(['2026-10-05', '2026-10-12'], array_column($weeks, 'period'));
        $this->assertSame(['2026-10-11', '2026-10-18'], array_column($weeks, 'to'));

        $months = $this->profit()->grouped('2026-01-20', '2026-03-10', 'month');
        $this->assertSame(['2026-01-20', '2026-02-01', '2026-03-01'], array_column($months, 'period'), 'the first month starts where the period starts');
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-10'], array_column($months, 'to'));
        $this->assertSame(['January 2026', 'February 2026', 'March 2026'], array_column($months, 'label'));

        $years = $this->profit()->grouped('2025-12-30', '2026-01-02', 'year');
        $this->assertSame(['2025', '2026'], array_column($years, 'label'));
    }
}
