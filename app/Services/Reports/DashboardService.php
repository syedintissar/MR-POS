<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures on the dashboard: the same numbers as the reports (profit comes from ProfitService), shown for a chosen period next to the
 * period just before it. Nothing here checks permissions: the controller decides what each person gets to ask for.
 */
class DashboardService
{
    public function __construct(private readonly ProfitService $profit) {}

    /**
     * @return array<string, mixed> invoices, total, tax, returns, net, average, services, service_value, and (when asked) the profit figures
     */
    public function figures(string $from, string $to, bool $withProfit, bool $withFinancials = false): array
    {
        $start = $from.' 00:00:00';
        $end = CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00';

        $s = DB::selectOne("SELECT COUNT(*) AS invoices, COALESCE(SUM(grand_total), 0) AS total, COALESCE(SUM(tax_total), 0) AS tax, COALESCE(SUM(line_discount_total + invoice_discount_amount), 0) AS discounts
                              FROM sales WHERE status = 'completed' AND sale_date >= ? AND sale_date < ?", [$start, $end]);
        $r = DB::selectOne("SELECT COALESCE(SUM(total), 0) AS total FROM sale_returns WHERE status = 'posted' AND return_date >= ? AND return_date < ?", [$start, $end]);
        $j = DB::selectOne("SELECT COUNT(*) AS jobs, COALESCE(SUM(grand_total), 0) AS total FROM service_jobs WHERE status = 'delivered' AND invoiced_at >= ? AND invoiced_at < ?", [$start, $end]);

        $total = Decimal::round((string) $s->total, 2);
        $returns = Decimal::round((string) $r->total, 2);
        $out = [
            'invoices' => (int) $s->invoices, 'total' => $total, 'tax' => Decimal::round((string) $s->tax, 2), 'discounts' => Decimal::round((string) $s->discounts, 2),
            'returns' => $returns, 'net' => Decimal::sub($total, $returns, 2), 'average' => (int) $s->invoices > 0 ? Decimal::div($total, (string) $s->invoices, 2) : '0.00',
            'jobs' => (int) $j->jobs, 'job_value' => Decimal::round((string) $j->total, 2),
        ];
        if ($withFinancials || $withProfit) {
            // money that really came in (customer credit is not money), how much of the invoices of the period is still on account, what was bought, what was spent
            $in = DB::selectOne("SELECT COALESCE(SUM(cp.amount), 0) AS total, COALESCE(SUM(CASE WHEN m.kind = 'cash' THEN cp.amount ELSE 0 END), 0) AS cash
                                   FROM customer_payments cp JOIN payment_methods m ON m.id = cp.payment_method_id
                                  WHERE cp.type = 'receipt' AND cp.status = 'posted' AND m.kind <> 'customer_credit' AND cp.payment_date >= ? AND cp.payment_date < ?", [$start, $end]);
            $left = DB::selectOne("SELECT COALESCE(SUM(due_amount), 0) AS total FROM sales WHERE status = 'completed' AND sale_date >= ? AND sale_date < ?", [$start, $end]);
            $bought = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS total FROM purchases WHERE status = 'received' AND purchase_date >= ? AND purchase_date < ?", [$start, $end]);
            $spent = DB::selectOne("SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE status = 'posted' AND expense_date >= ? AND expense_date < ?", [$start, $end]);
            $out += [
                'received' => Decimal::round((string) $in->total, 2), 'received_cash' => Decimal::round((string) $in->cash, 2), 'on_account' => Decimal::round((string) $left->total, 2),
                'purchases' => Decimal::round((string) $bought->total, 2), 'purchase_count' => (int) $bought->n, 'expenses' => Decimal::round((string) $spent->total, 2),
            ];
        }
        if ($withProfit) {
            $p = $this->profit->summary($from, $to);
            $out += ['gross_profit' => $p['gross_profit'], 'net_profit' => $p['net_profit'], 'margin' => $p['gross_margin'], 'service_profit' => $p['service_profit'], 'service_revenue' => $p['service_revenue']];
        }

        return $out;
    }

    /**
     * The latest ten of each kind the person may see: invoices, expenses, payments received, service jobs.
     *
     * @return array<string, Collection>
     */
    public function latest(bool $sales, bool $expenses, bool $payments, bool $jobs): array
    {
        $out = [];
        if ($sales) {
            $out['sales'] = Sale::query()->completed()->with('customer:id,name,is_walk_in')->orderByDesc('sale_date')->orderByDesc('id')->limit(10)->get();
        }
        if ($expenses) {
            $out['expenses'] = Expense::query()->where('status', 'posted')->with('category:id,name')->orderByDesc('expense_date')->orderByDesc('id')->limit(10)->get();
        }
        if ($payments) {
            $out['payments'] = CustomerPayment::query()->where('type', 'receipt')->where('status', 'posted')->with(['customer:id,name,is_walk_in', 'method:id,name'])->orderByDesc('payment_date')->orderByDesc('id')->limit(10)->get();
        }
        if ($jobs) {
            $out['jobs'] = ServiceJob::query()->where('status', '<>', 'cancelled')->with('customer:id,name,is_walk_in')->orderByDesc('received_at')->orderByDesc('id')->limit(10)->get();
        }

        return $out;
    }

    /** The period just before this one, the same length. @return array{0: string, 1: string} */
    public function previous(string $from, string $to): array
    {
        $a = CarbonImmutable::parse($from);
        $days = (int) $a->diffInDays(CarbonImmutable::parse($to)) + 1;

        return [$a->subDays($days)->toDateString(), $a->subDay()->toDateString()];
    }

    /** "+12.5" / "-8.0" (percent change from $before to $now), or null when there is nothing to compare with. */
    public static function change(string $now, string $before): ?string
    {
        if (! Decimal::isPositive($before)) {
            return null;
        }

        return Decimal::round(Decimal::mul(Decimal::div(Decimal::sub($now, $before, 4), $before, 6), '100', 4), 1);
    }

    /**
     * Sales (and gross profit) over the period, one bar per day, week or month depending on how long the period is.
     *
     * @return array{group: string, labels: list<string>, sales: list<string>, profit: ?list<string>}
     */
    public function trend(string $from, string $to, bool $withProfit): array
    {
        $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $group = $days <= 45 ? 'day' : ($days <= 200 ? 'week' : 'month');
        $start = $from.' 00:00:00';
        $end = CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00';

        $b = Periods::sql('sale_date', $group);
        $byKey = [];
        foreach (DB::select("SELECT {$b} AS b, SUM(grand_total) AS total FROM sales WHERE status = 'completed' AND sale_date >= ? AND sale_date < ? GROUP BY b", [$start, $end]) as $row) {
            $byKey[(string) $row->b] = Decimal::round((string) $row->total, 2);
        }
        $rb = Periods::sql('return_date', $group);
        foreach (DB::select("SELECT {$rb} AS b, SUM(total) AS total FROM sale_returns WHERE status = 'posted' AND return_date >= ? AND return_date < ? GROUP BY b", [$start, $end]) as $row) {
            $byKey[(string) $row->b] = Decimal::sub($byKey[(string) $row->b] ?? '0.00', (string) $row->total, 2);
        }

        $labels = [];
        $sales = [];
        foreach (Periods::between($from, $to, $group) as $p) {
            $labels[] = $group === 'day' ? CarbonImmutable::parse($p['from'])->format('d M') : ($group === 'month' ? CarbonImmutable::parse($p['from'])->format('M Y') : CarbonImmutable::parse($p['from'])->format('d M'));
            $sales[] = $byKey[$p['key']] ?? '0.00';
        }

        $profit = $withProfit ? array_map(fn ($r) => $r['gross_profit'], $this->profit->grouped($from, $to, $group)) : null;

        return ['group' => $group, 'labels' => $labels, 'sales' => $sales, 'profit' => $profit];
    }

    /**
     * The last twelve months of sales next to the same months a year earlier (invoiced minus returned).
     *
     * @return array{labels: list<string>, this_year: list<string>, last_year: list<string>}
     */
    public function yearOnYear(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $first = $today->startOfMonth()->subMonthsNoOverflow(11);
        $from = $first->subYear()->toDateString().' 00:00:00';
        $to = $today->addDay()->toDateString().' 00:00:00';

        $byMonth = [];
        foreach (DB::select("SELECT DATE_FORMAT(sale_date, '%Y-%m') AS m, SUM(grand_total) AS total FROM sales WHERE status = 'completed' AND sale_date >= ? AND sale_date < ? GROUP BY m", [$from, $to]) as $row) {
            $byMonth[$row->m] = Decimal::round((string) $row->total, 2);
        }
        foreach (DB::select("SELECT DATE_FORMAT(return_date, '%Y-%m') AS m, SUM(total) AS total FROM sale_returns WHERE status = 'posted' AND return_date >= ? AND return_date < ? GROUP BY m", [$from, $to]) as $row) {
            $byMonth[$row->m] = Decimal::sub($byMonth[$row->m] ?? '0.00', (string) $row->total, 2);
        }

        $labels = [];
        $thisYear = [];
        $lastYear = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $first->addMonthsNoOverflow($i);
            $labels[] = $m->format('M');
            $thisYear[] = $byMonth[$m->format('Y-m')] ?? '0.00';
            $lastYear[] = $byMonth[$m->subYear()->format('Y-m')] ?? '0.00';
        }

        return ['labels' => $labels, 'this_year' => $thisYear, 'last_year' => $lastYear];
    }

    /** The five products that earned the most in the period (without tax, after returns). @return list<array{id: int, name: string, qty: string, revenue: string}> */
    public function topProducts(string $from, string $to, int $limit = 5): array
    {
        $start = $from.' 00:00:00';
        $end = CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00';
        $rows = [];
        foreach (DB::select("SELECT p.id, p.name, SUM(i.quantity) AS qty, SUM(i.net_amount) AS revenue
                               FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id
                              WHERE s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ? GROUP BY p.id, p.name ORDER BY revenue DESC, qty DESC LIMIT {$limit}", [$start, $end]) as $r) {
            $rows[] = ['id' => (int) $r->id, 'name' => $r->name, 'qty' => Decimal::trim((string) $r->qty), 'revenue' => Decimal::round((string) $r->revenue, 2)];
        }

        return $rows;
    }
}
