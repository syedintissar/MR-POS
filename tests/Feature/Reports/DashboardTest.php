<?php

namespace Tests\Feature\Reports;

use App\Actions\Service\ReceiveServiceJob;
use App\Models\User;
use App\Services\Reports\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\BuildsKnownShop;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The dashboard: figures agree with the reports, profit shows only to people allowed to see it, and every period works. */
class DashboardTest extends TestCase
{
    use ActsAsRoles;
    use BuildsKnownShop;
    use RefreshesSeededDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStaff();
    }

    private function service(): DashboardService
    {
        return app(DashboardService::class);
    }

    // ---------------------------------------------------------------- the page

    public function test_a_manager_sees_the_known_shop_at_a_glance_with_profit(): void
    {
        $this->dataset();
        $this->as('Manager');

        $this->get('/')->assertOk()
            ->assertSee('Business at a glance')
            ->assertSee('Invoiced')->assertSee('Rs. 840.00')
            ->assertSee('After returns')->assertSee('Rs. 540.00')
            ->assertSee('Service jobs delivered')->assertSee('Rs. 700.00')
            ->assertSee('Gross profit')->assertSee('Rs. 640.00')->assertSee('51.6% of revenue')
            ->assertSee('Expenses')->assertSee('Rs. 300.00')
            ->assertSee('Net profit')->assertSee('Rs. 80.00')
            ->assertSee('Best sellers in this period')
            ->assertSee('Money received')->assertSee('Rs. 1,540.00')->assertSee('Left on account')->assertSee('Purchases')->assertSee('Rs. 1,100.00')
            ->assertSee('Latest activity')->assertSee('Recent sales')->assertSee('Recent payments received')->assertSee('Recent expenses')->assertSee('Recent service jobs')
            ->assertSee('INV-000001')->assertSee('EXP-000001')
            ->assertSee('<svg', false)->assertSee('chart-bar', false)
            ->assertSee('Last 12 months compared with the year before');
    }

    public function test_profit_is_only_for_people_who_may_see_profit(): void
    {
        $this->dataset();

        // sees every sale, but not profit
        $this->asWithOnly(['dashboard.view', 'sales.view_all']);
        $this->get('/')->assertOk()->assertSee('Business at a glance')->assertSee('Rs. 840.00')
            ->assertDontSee('Gross profit')->assertDontSee('Net profit')->assertDontSee('Rs. 640.00')->assertDontSee('Rs. 80.00')->assertDontSee('/reports/profit', false)
            ->assertSee('Recent sales')->assertDontSee('Money received')->assertDontSee('Recent expenses')->assertDontSee('Recent payments received')->assertDontSee('Purchases');

        // profit permission without the dashboard financial permission is still profit
        $this->asWithOnly(['dashboard.view', 'sales.view_all', 'reports.profit']);
        $this->get('/')->assertOk()->assertSee('Gross profit')->assertSee('Rs. 640.00');

        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role);
            $this->get('/')->assertOk()->assertDontSee('Business at a glance')->assertDontSee('Gross profit')->assertDontSee('Net profit')->assertDontSee('Rs. 640.00')->assertDontSee('Rs. 80.00')
                ->assertDontSee('Recent payments received')->assertDontSee('Recent expenses')->assertDontSee('Recent sales')->assertDontSee('Money received');
        }
    }

    public function test_every_period_works_and_bad_ones_fall_back_to_today(): void
    {
        $this->dataset();
        $this->as('Manager');

        foreach (['today', 'yesterday', 'this_week', 'this_month', 'last_month'] as $preset) {
            $this->get('/?preset='.$preset)->assertOk()->assertSee('Business at a glance');
        }
        $this->get('/?preset=yesterday')->assertOk()->assertSee('Rs. 0.00');
        $this->get('/?preset=custom&from=2020-01-01&to=2020-01-31')->assertOk()->assertSee('01 Jan 2020 to 31 Jan 2020')->assertSee('Rs. 0.00');
        $this->get('/?preset=custom&from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString())->assertOk()->assertSee('Rs. 840.00');

        foreach (['/?preset=bogus', '/?preset=custom&from=nonsense&to=2026-01-01', '/?preset=custom&from=2026-02-01&to=2026-01-01', '/?preset=custom&from=1990-01-01&to=2026-01-01', '/?preset[]=x'] as $bad) {
            $this->get($bad)->assertOk()->assertSee(now()->format('d M Y'));
        }
    }

    public function test_a_technician_sees_only_their_own_open_jobs(): void
    {
        $mine = $this->openJob();
        $theirs = app(ReceiveServiceJob::class)->execute([
            'contact_name' => 'Someone Else', 'contact_phone' => '0300-1111111', 'service_type_id' => $mine->service_type_id, 'device_type' => 'printer',
            'reported_problem' => 'Jam', 'technician_id' => User::factory()->withRole('Technician')->create()->id,
        ], $this->manager);

        $this->actingAs($this->tech)->get('/')->assertOk()->assertSee('My open jobs')->assertSee($mine->job_no)->assertDontSee($theirs->job_no);
    }

    // ---------------------------------------------------------------- the numbers

    public function test_figures_match_the_profit_engine_and_compare_with_the_period_before(): void
    {
        $this->dataset();
        $today = now()->toDateString();

        $now = $this->service()->figures($today, $today, true);
        $this->assertSame([2, '840.00', '300.00', '540.00', '420.00'], [$now['invoices'], $now['total'], $now['returns'], $now['net'], $now['average']]);
        $this->assertSame([1, '700.00'], [$now['jobs'], $now['job_value']]);
        $this->assertSame(['640.00', '80.00', '300.00', '600.00'], [$now['gross_profit'], $now['net_profit'], $now['expenses'], $now['service_profit']]);
        $this->assertArrayNotHasKey('gross_profit', $this->service()->figures($today, $today, false), 'profit is not even computed for people who may not see it');
        $this->assertArrayNotHasKey('received', $this->service()->figures($today, $today, false), 'nor the money figures');

        $money = $this->service()->figures($today, $today, false, true);
        $this->assertSame(['1540.00', '1540.00', '0.00', '1100.00', 1, '300.00'], [$money['received'], $money['received_cash'], $money['on_account'], $money['purchases'], $money['purchase_count'], $money['expenses']]);
        $this->assertArrayNotHasKey('gross_profit', $money);

        [$from, $to] = $this->service()->previous($today, $today);
        $this->assertSame([now()->subDay()->toDateString(), now()->subDay()->toDateString()], [$from, $to]);
        [$from, $to] = $this->service()->previous('2026-10-08', '2026-10-14');   // seven days
        $this->assertSame(['2026-10-01', '2026-10-07'], [$from, $to]);

        $this->assertSame('100.0', DashboardService::change('200.00', '100.00'));
        $this->assertSame('-50.0', DashboardService::change('50.00', '100.00'));
        $this->assertNull(DashboardService::change('50.00', '0.00'), 'no percentage of nothing');
    }

    public function test_the_trend_uses_days_weeks_or_months_to_suit_the_period(): void
    {
        $a = $this->stocked('50', '100', ['sale_price' => '150']);
        $this->sell([['product_id' => $a->id, 'quantity' => '2']]);

        $days = $this->service()->trend(now()->subDays(9)->toDateString(), now()->toDateString(), true);
        $this->assertSame('day', $days['group']);
        $this->assertCount(10, $days['labels']);
        $this->assertSame('300.00', $days['sales'][9]);
        $this->assertSame('100.00', $days['profit'][9]);
        $this->assertNull($this->service()->trend(now()->toDateString(), now()->toDateString(), false)['profit']);

        $this->assertSame('week', $this->service()->trend(now()->subDays(100)->toDateString(), now()->toDateString(), false)['group']);
        $this->assertSame('month', $this->service()->trend(now()->subDays(300)->toDateString(), now()->toDateString(), false)['group']);
    }

    public function test_the_year_on_year_chart_lines_up_each_month_with_the_same_month_a_year_earlier(): void
    {
        $a = $this->stocked('50', '100', ['sale_price' => '150']);
        $this->sell([['product_id' => $a->id, 'quantity' => '2']]);                    // 300 this month
        $old = $this->sell([['product_id' => $a->id, 'quantity' => '1']]);             // 150, same month last year
        $older = $this->sell([['product_id' => $a->id, 'quantity' => '3']]);           // 450, two years ago: out of both windows
        DB::table('sales')->where('id', $old->id)->update(['sale_date' => Carbon::now()->subYear()->setTime(12, 0)]);
        DB::table('sales')->where('id', $older->id)->update(['sale_date' => Carbon::now()->subYears(2)->setTime(12, 0)]);

        $y = $this->service()->yearOnYear(CarbonImmutable::today());

        $this->assertCount(12, $y['labels']);
        $this->assertSame(now()->format('M'), $y['labels'][11], 'the last bar is this month');
        $this->assertSame(['300.00', '150.00'], [$y['this_year'][11], $y['last_year'][11]]);
        $this->assertSame(450.0, array_sum(array_map('floatval', $y['this_year'])) + array_sum(array_map('floatval', $y['last_year'])));
    }

    // ---------------------------------------------------------------- the chart

    public function test_the_chart_draws_bars_handles_losses_and_empty_data(): void
    {
        $html = Blade::render('<x-chart.bars :labels="$l" :series="$s" title="Test"/>', [
            'l' => ['Mon', 'Tue', 'Wed'],
            's' => [['name' => 'Profit', 'values' => ['100.00', '-40.00', '0.00'], 'color' => '#059669']],
        ]);
        $this->assertSame(2, substr_count($html, 'class="chart-bar"'), 'a zero day has no bar');
        $this->assertStringContainsString('Tue · Profit: -40.00', $html);
        $this->assertStringContainsString('fill="#94a3b8"', $html, 'a loss is grey, not the series colour');

        $empty = Blade::render('<x-chart.bars :labels="$l" :series="$s" title="Empty"/>', ['l' => ['A', 'B'], 's' => [['name' => 'Sales', 'values' => ['0.00', '0.00'], 'color' => '#c0121b']]]);
        $this->assertStringContainsString('<svg', $empty);
        $this->assertStringNotContainsString('chart-bar', $empty);
        $this->assertStringNotContainsString('NAN', strtoupper($empty));

        $two = Blade::render('<x-chart.bars :labels="$l" :series="$s" title="Two"/>', ['l' => ['A'], 's' => [
            ['name' => 'This', 'values' => ['10'], 'color' => '#c0121b'], ['name' => 'Last', 'values' => ['5'], 'color' => '#cbd5e1'],
        ]]);
        $this->assertSame(2, substr_count($two, 'class="chart-bar"'));
        $this->assertStringContainsString('Last', $two, 'a legend is shown when there are two series');
    }
}
