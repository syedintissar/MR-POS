<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Reports\ReportCatalogue;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\BuildsKnownShop;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The report set: every report opens, prints and exports; the figures agree with the known shop; cost, profit and export follow their permissions. */
class ReportsTest extends TestCase
{
    use ActsAsRoles;
    use BuildsKnownShop;
    use RefreshesSeededDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStaff();
    }

    private function build(string $key, array $input = ['preset' => 'today'], ?User $as = null): ReportResult
    {
        return app(ReportCatalogue::class)->build($key, ReportFilters::make($input), $as ?? User::factory()->withRole('Admin')->create());
    }

    /** @return array<string, mixed> the table's row whose first column equals $first */
    private function row(ReportResult $r, string $first, int $table = 0): array
    {
        $key = $r->tables[$table]['columns'][0]['key'];
        foreach ($r->tables[$table]['rows'] as $row) {
            if (($row[$key] ?? null) === $first) {
                return $row;
            }
        }
        $this->fail("No row [{$first}] in table {$table} of {$r->title}.");
    }

    private function labels(ReportResult $r, int $table = 0): array
    {
        return array_column($r->tables[$table]['columns'], 'label');
    }

    // ---------------------------------------------------------------- every report works

    public function test_every_report_opens_prints_and_exports_for_a_manager(): void
    {
        $this->dataset();
        $this->as('Manager');

        $this->get('/reports')->assertOk()->assertSee('Sales summary')->assertSee('Profit')->assertSee('Closing summary');
        foreach (ReportCatalogue::REPORTS as $key => $def) {
            $this->get("/reports/{$key}")->assertOk()->assertSee($def['title'], false);
            $this->get("/reports/{$key}?preset=last_year")->assertOk();
            $this->get("/reports/{$key}/print")->assertOk()->assertSee('Printed');

            $csv = $this->get("/reports/{$key}/export/csv");
            $csv->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
            $this->assertStringContainsString($def['title'], $csv->getContent());
            $this->assertStringContainsString('attachment; filename="'.$key, $csv->headers->get('Content-Disposition'));

            $this->assertStringStartsWith('PK', $this->get("/reports/{$key}/export/xlsx")->assertOk()->getContent(), "{$key} as Excel");
            $this->assertStringStartsWith('%PDF', $this->get("/reports/{$key}/export/pdf")->assertOk()->getContent(), "{$key} as PDF");
        }
        $this->assertSame(count(ReportCatalogue::REPORTS), AuditLog::query()->where('event', 'report_exported')->where('description', 'like', '%as CSV%')->count());
    }

    public function test_every_filter_a_report_offers_is_accepted(): void
    {
        $this->dataset();
        $this->as('Manager');
        $any = ['customer_id' => 1, 'supplier_id' => 1, 'category_id' => 1, 'user_id' => 1, 'technician_id' => 1, 'method_id' => 1];

        foreach (ReportCatalogue::REPORTS as $key => $def) {
            foreach (array_keys($def['statuses'] ?? []) as $status) {
                $this->get("/reports/{$key}?".http_build_query($any + ['status' => $status, 'preset' => 'this_year', 'group' => 'week']))->assertOk();
            }
            $this->get("/reports/{$key}?".http_build_query($any + ['preset' => 'custom', 'from' => '2020-01-01', 'to' => '2020-02-01']))->assertOk();
        }
    }

    // ---------------------------------------------------------------- the figures

    public function test_sales_reports_agree_with_the_known_shop(): void
    {
        $this->dataset();

        $summary = $this->build('sales-summary');
        $today = $summary->tables[0]['rows'][0];
        $this->assertSame([2, '900.00', '60.00', '840.00', '300.00', '540.00'], [$today['invoices'], $today['gross'], $today['discounts'], $today['total'], $today['returns'], $today['net']]);
        $this->assertSame('540.00', $summary->tables[0]['totals']['net']);

        $products = $this->build('sales-by-product');
        $this->assertSame(['40.00', '540.00', '500.00'], [$products->tables[0]['totals']['profit'], $products->tables[0]['totals']['revenue'], $products->tables[0]['totals']['cost']]);
        $this->assertSame(['540.00', '0.00'], array_column($products->tables[0]['rows'], 'revenue'), 'the 600 item less its 60 discount, and the item that was fully returned');
        $this->assertSame('140.00', $products->tables[0]['rows'][0]['profit']);
        $this->assertSame('-100.00', $products->tables[0]['rows'][1]['profit'], 'two units returned, one of them damaged: its cost is lost');

        $categories = $this->build('sales-by-category');
        $this->assertSame('40.00', $categories->tables[0]['totals']['profit']);

        $customers = $this->build('sales-by-customer');
        $this->assertSame('Walk-in customers', $customers->tables[0]['rows'][0]['customer']);
        $this->assertSame([2, '840.00', '300.00', '540.00'], [$customers->tables[0]['totals']['invoices'], $customers->tables[0]['totals']['total'], $customers->tables[0]['totals']['returns'], $customers->tables[0]['totals']['net']]);

        $cashier = $this->build('sales-by-cashier');
        $this->assertSame([2, 1, '300.00'], [$cashier->tables[0]['totals']['invoices'], $cashier->tables[0]['totals']['cancelled'], $cashier->tables[0]['totals']['returns']], 'one cancelled invoice is visible');

        $register = $this->build('sales-register');
        $this->assertCount(3, $register->tables[0]['rows']);
        $this->assertSame(['Completed', 'Completed', 'Cancelled'], array_column($register->tables[0]['rows'], 'status'));
        $this->assertSame('840.00', $register->tables[0]['totals']['total'], 'cancelled invoices are listed but not added up');
        $this->assertCount(1, $this->build('sales-register', ['preset' => 'today', 'status' => 'voided'])->tables[0]['rows']);

        $returns = $this->build('sales-returns');
        $this->assertCount(2, $returns->tables[0]['rows']);
        $this->assertSame(['300.00', '300.00'], [$returns->tables[0]['totals']['total'], $returns->tables[0]['totals']['refund']]);
    }

    public function test_money_expense_and_profit_reports_agree_with_the_known_shop(): void
    {
        $this->dataset();

        $money = $this->build('payments-by-method');
        $cash = $this->row($money, 'Cash');
        $this->assertSame(['1540.00', '300.00', '0.00', '300.00', '940.00'], [$cash['received'], $cash['expenses'], $cash['suppliers_paid'], $cash['refunded'], $cash['net']], '300 + 540 sold, 700 service; 300 refunded and 300 spent; the cancelled sale and expense do not count');
        $this->assertSame('940.00', $money->tables[0]['totals']['net']);

        $expenses = $this->build('expenses');
        $this->assertSame([['Electricity', 1, '300.00']], array_map(fn ($r) => [$r['category'], $r['count'], $r['total']], $expenses->tables[0]['rows']));
        $this->assertCount(2, $this->build('expenses', ['preset' => 'today', 'status' => 'all'])->tables[1]['rows']);

        $profit = $this->build('profit');
        $statement = collect($profit->tables[0]['rows'])->keyBy('label');
        $this->assertSame('640.00', $statement['GROSS PROFIT']['amount']);
        $this->assertSame('51.6% of revenue', $statement['GROSS PROFIT']['note']);
        $this->assertSame('80.00', $statement['NET PROFIT']['amount']);
        $this->assertSame('466.67', $statement['  Service charges (labour and other charges)']['amount']);
        $this->assertSame('-100.00', $statement['Cost of parts used on those jobs']['amount']);
        $this->assertSame('100.00', $statement['  of which: returned goods that were not put back (damaged or defective), at cost']['amount']);
        $this->assertSame('80.00', $profit->tables[1]['totals']['net']);

        $closing = $this->build('daily-closing');
        $this->assertSame([2, 840.0], [$closing->tables[0]['rows'][0]['count'], (float) $closing->tables[0]['rows'][0]['amount']]);
        $this->assertSame('940.00', $this->row($closing, 'Cash', 1)['net']);
        $this->assertSame('80.00', $this->row($closing, 'Net profit (after expenses and losses)', 2)['amount']);
    }

    public function test_stock_purchase_people_and_service_reports_agree_with_the_known_shop(): void
    {
        $this->dataset();

        $valuation = $this->build('stock-valuation');
        $this->assertNotEmpty($valuation->tables[0]['rows']);
        $this->assertSame(array_sum(array_map(fn ($r) => (float) $r['value'], $valuation->tables[0]['rows'])), (float) $valuation->tables[0]['totals']['value']);
        $this->assertGreaterThan(count($this->build('stock-valuation')->tables[0]['rows']) - 1, count($this->build('stock-valuation', ['status' => 'all'])->tables[0]['rows']));

        $movers = $this->build('top-and-slow');
        $this->assertSame(2, count($movers->tables[0]['rows']));
        $this->assertNotEmpty($movers->tables[1]['rows'], 'the products nobody bought');

        $purchases = $this->build('purchases');
        $this->assertSame('1100.00', $purchases->tables[0]['totals']['total']);
        $this->assertSame(['200.00', '900.00'], [$purchases->tables[0]['totals']['returned'], $purchases->tables[0]['totals']['due']]);
        $this->assertSame('200.00', $purchases->tables[1]['totals']['total']);

        $suppliers = $this->build('supplier-balances');
        $this->assertSame('900.00', $suppliers->tables[0]['rows'][0]['balance']);
        $this->assertSame('900.00', $suppliers->tables[0]['rows'][0]['current']);
        $this->assertSame('0.00', $suppliers->tables[0]['rows'][0]['other']);

        $this->assertSame([], $this->build('customer-balances')->tables[0]['rows'], 'no named customer owes anything');

        $jobs = $this->build('service-jobs');
        $this->assertCount(3, $jobs->tables[0]['rows']);
        $delivered = collect($jobs->tables[0]['rows'])->firstWhere('status', 'Delivered');
        $this->assertSame(['700.00', '100.00'], [$delivered['total'], $delivered['parts_cost']]);
        $this->assertSame('700.00', $jobs->tables[0]['totals']['total'], 'the cancelled job is listed at nothing');
        $tech = $jobs->tables[1]['rows'][0];
        $this->assertSame([3, 1, '700.00'], [$tech['received'], $tech['delivered'], $tech['revenue']]);
        $this->assertCount(1, $this->build('service-jobs', ['preset' => 'today', 'status' => 'cancelled'])->tables[0]['rows']);
    }

    // ---------------------------------------------------------------- who may see what

    public function test_long_reports_offer_a_find_box_and_short_ones_do_not(): void
    {
        $this->dataset();
        $this->as('Manager');

        $this->get('/reports/profit')->assertOk()->assertSee('Find in this report')->assertSee('data-report-row', false);
        $this->get('/reports/customer-balances')->assertOk()->assertDontSee('Find in this report');
    }

    public function test_cashiers_and_technicians_have_no_reports(): void
    {
        $this->dataset();

        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role);
            $this->get('/reports')->assertForbidden();
            foreach (array_keys(ReportCatalogue::REPORTS) as $key) {
                $this->get("/reports/{$key}")->assertForbidden();
                $this->get("/reports/{$key}/print")->assertForbidden();
                $this->get("/reports/{$key}/export/csv")->assertForbidden();
            }
            $this->get('/')->assertOk()->assertDontSee('/reports', false);
        }
    }

    public function test_each_report_needs_its_own_permission(): void
    {
        $this->dataset();
        $this->asWithOnly(['reports.sales']);

        $this->get('/reports')->assertOk()->assertSee('Sales summary')->assertDontSee('Expenses')->assertDontSee('Stock valuation')->assertDontSee('Service jobs and technicians');
        $this->get('/reports/sales-summary')->assertOk();
        $this->get('/reports/payments-by-method')->assertOk();
        foreach (['profit', 'expenses', 'stock-valuation', 'purchases', 'supplier-balances', 'customer-balances', 'service-jobs', 'top-and-slow'] as $key) {
            $this->get("/reports/{$key}")->assertForbidden();
            $this->get("/reports/{$key}/print")->assertForbidden();
        }
        $this->get('/reports/no-such-report')->assertNotFound();

        $this->asWithOnly(['reports.profit'])->get('/reports/profit')->assertOk()->assertSee('NET PROFIT');
        $this->asWithOnly(['reports.expenses'])->get('/reports/expenses')->assertOk();
        $this->asWithOnly(['reports.inventory'])->get('/reports/stock-valuation')->assertOk();
        $this->asWithOnly(['reports.purchases'])->get('/reports/purchases')->assertOk();
        $this->asWithOnly(['reports.suppliers'])->get('/reports/supplier-balances')->assertOk();
        $this->asWithOnly(['reports.customers'])->get('/reports/customer-balances')->assertOk();
        $this->asWithOnly(['reports.service'])->get('/reports/service-jobs')->assertOk();
    }

    public function test_cost_and_profit_columns_are_cut_out_for_people_without_their_permissions(): void
    {
        $this->dataset();

        $plain = $this->build('sales-by-product', ['preset' => 'today'], User::factory()->withRole('Cashier')->create()->givePermissionTo('reports.sales'));
        $this->assertSame(['Product', 'SKU', 'Category', 'Sold', 'Returned', 'Sales (without tax, after returns)'], $this->labels($plain));
        $this->assertArrayNotHasKey('cost', $plain->tables[0]['rows'][0]);
        $this->assertArrayNotHasKey('profit', $plain->tables[0]['totals']);

        $withCost = $this->build('sales-by-product', ['preset' => 'today'], User::factory()->withRole('Cashier')->create()->givePermissionTo(['reports.sales', 'products.view_cost']));
        $this->assertContains('Cost', $this->labels($withCost));
        $this->assertNotContains('Profit', $this->labels($withCost));
        $this->assertArrayNotHasKey('profit', $withCost->tables[0]['rows'][0]);

        $full = $this->build('sales-by-product', ['preset' => 'today'], User::factory()->withRole('Cashier')->create()->givePermissionTo(['reports.sales', 'products.view_cost', 'reports.profit']));
        $this->assertContains('Profit', $this->labels($full));

        // through the pages too
        $this->asWithOnly(['reports.sales'])->get('/reports/sales-by-product')->assertOk()->assertDontSee('Cost')->assertDontSee('Margin');
        $this->asWithOnly(['reports.inventory'])->get('/reports/stock-valuation')->assertOk()->assertDontSee('Value at cost')->assertDontSee('Average cost')->assertSee('Value at sale price');
        $this->asWithOnly(['reports.inventory', 'products.view_cost'])->get('/reports/stock-valuation')->assertOk()->assertSee('Value at cost');
        $this->asWithOnly(['reports.service'])->get('/reports/service-jobs')->assertOk()->assertDontSee('Parts cost');
        $this->asWithOnly(['reports.sales'])->get('/reports/daily-closing')->assertOk()->assertDontSee('Profit for the period')->assertDontSee('Net profit');
        $this->asWithOnly(['reports.sales', 'reports.profit'])->get('/reports/daily-closing')->assertOk()->assertSee('Net profit');
        $this->asWithOnly(['reports.inventory'])->get('/reports/top-and-slow')->assertOk()->assertDontSee('Profit');
    }

    public function test_exports_need_their_own_permission_and_never_carry_hidden_columns(): void
    {
        $this->dataset();

        $this->asWithOnly(['reports.sales']);
        $this->get('/reports/sales-by-product')->assertOk()->assertDontSee('/export/', false);
        $this->get('/reports/sales-by-product/print')->assertOk();   // looking at it on paper is looking at it
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->get("/reports/sales-by-product/export/{$format}")->assertForbidden();
        }
        $this->assertSame(0, AuditLog::query()->where('event', 'report_exported')->count());

        $this->asWithOnly(['reports.sales', 'reports.export']);
        $this->get('/reports/sales-by-product')->assertOk()->assertSee('/export/csv', false);
        $csv = $this->get('/reports/sales-by-product/export/csv')->assertOk()->getContent();
        $this->assertStringContainsString('Sales (without tax, after returns)', $csv);
        $this->assertStringNotContainsString('Cost', $csv);
        $this->assertStringNotContainsString('Profit', $csv);
        $this->assertStringNotContainsString('Margin', $csv);
        $this->assertStringStartsWith('%PDF', $this->get('/reports/sales-by-product/export/pdf')->assertOk()->getContent());

        $log = AuditLog::query()->where('event', 'report_exported')->latest('id')->firstOrFail();
        $this->assertSame('sales-by-product', $log->new_values['report']);

        $this->asWithOnly(['reports.sales', 'reports.export', 'products.view_cost', 'reports.profit']);
        $full = $this->get('/reports/sales-by-product/export/csv')->assertOk()->getContent();
        $this->assertStringContainsString('Cost', $full);
        $this->assertStringContainsString('Profit', $full);

        $this->asWithOnly(['reports.export']);   // an export permission alone opens nothing
        $this->get('/reports/sales-summary/export/csv')->assertForbidden();
    }

    // ---------------------------------------------------------------- filters

    public function test_bad_periods_are_refused_politely(): void
    {
        $this->as('Manager');

        $this->get('/reports/sales-summary?preset=custom&from=2026-02-01&to=2026-01-01')->assertRedirect(route('reports.show', 'sales-summary'))->assertSessionHas('error');
        $this->get('/reports/sales-summary?preset=custom&from=2000-01-01&to=2026-01-01')->assertRedirect()->assertSessionHas('error');
        $this->get('/reports/sales-summary?preset=custom&from=nonsense')->assertSessionHasErrors('from');
        $this->get('/reports/sales-summary?group=fortnight')->assertSessionHasErrors('group');
        $this->get('/reports/sales-summary?preset=whenever')->assertSessionHasErrors('preset');
        $this->get('/reports/sales-register?status=bogus')->assertOk();   // an unknown status is ignored, not an error
        $this->get('/reports/sales-summary/export/odt')->assertNotFound();
        $this->get('/reports/sales-summary?preset=today')->assertOk()->assertSee(now()->format('d M Y'));
    }

    public function test_the_period_presets_mean_what_they_say(): void
    {
        $today = CarbonImmutable::parse('2026-10-14');   // a Wednesday

        $this->assertSame(['2026-10-14', '2026-10-14'], ReportFilters::resolve('today', $today));
        $this->assertSame(['2026-10-13', '2026-10-13'], ReportFilters::resolve('yesterday', $today));
        $this->assertSame(['2026-10-12', '2026-10-14'], ReportFilters::resolve('this_week', $today));
        $this->assertSame(['2026-10-05', '2026-10-11'], ReportFilters::resolve('last_week', $today));
        $this->assertSame(['2026-09-15', '2026-10-14'], ReportFilters::resolve('last_30', $today));
        $this->assertSame(['2026-10-01', '2026-10-14'], ReportFilters::resolve('this_month', $today));
        $this->assertSame(['2026-09-01', '2026-09-30'], ReportFilters::resolve('last_month', $today));
        $this->assertSame(['2026-01-01', '2026-10-14'], ReportFilters::resolve('this_year', $today));
        $this->assertSame(['2025-01-01', '2025-12-31'], ReportFilters::resolve('last_year', $today));
        $this->assertSame(['2025-12-01', '2025-12-31'], ReportFilters::resolve('last_month', CarbonImmutable::parse('2026-01-05')));

        $f = ReportFilters::make(['preset' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31', 'customer_id' => '7', 'status' => 'all', 'junk' => 'x'], $today);
        $this->assertSame([7, 'all'], [$f->id('customer_id'), $f->str('status')]);
        $this->assertSame(31, $f->days());
        $this->assertSame('2026-04-01 00:00:00', $f->endExclusive());
        $this->assertSame('01 Mar 2026 to 31 Mar 2026', $f->label());

        $this->expectException(InvalidArgumentException::class);
        new ReportFilters('2026-02-01', '2026-01-01');
    }

    // ---------------------------------------------------------------- files

    public function test_a_spreadsheet_cannot_be_made_to_run_a_formula(): void
    {
        $evil = ['=HYPERLINK("http://x","click")', '+1+1', '-2+3', '@SUM(A1)'];
        $result = (new ReportResult('Test', 'x'))->table(null, [ReportResult::col('name', 'Name'), ReportResult::col('amount', 'Amount', 'money')],
            array_map(fn ($e) => ['name' => $e, 'amount' => '12.50'], $evil), ['name' => 'Total', 'amount' => '50.00']);
        $exporter = new ReportExporter;

        $csv = $exporter->csv($result);
        foreach ($evil as $e) {
            $this->assertStringContainsString('" '.str_replace('"', '""', $e).'"', str_replace(["\r"], '', $csv), "csv: {$e}");
        }
        $this->assertStringContainsString('12.5', $csv, 'money is a plain number');

        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $exporter->xlsx($result));
        $reader = new XlsxReader;
        $reader->open($path);
        $seen = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $seen[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        $names = array_column(array_slice($seen, 3, 4), 0);
        $this->assertSame(array_map(fn ($e) => ' '.$e, $evil), $names);
        $this->assertSame(12.5, array_slice($seen, 3, 1)[0][1], 'a real number the spreadsheet can add up');
    }

    public function test_a_report_with_nothing_in_it_still_makes_valid_files(): void
    {
        $this->as('Manager');   // an empty shop

        foreach (array_keys(ReportCatalogue::REPORTS) as $key) {
            $this->get("/reports/{$key}")->assertOk();
            $this->assertStringStartsWith('PK', $this->get("/reports/{$key}/export/xlsx")->assertOk()->getContent());
            $this->assertStringStartsWith('%PDF', $this->get("/reports/{$key}/export/pdf")->assertOk()->getContent());
        }
    }
}
