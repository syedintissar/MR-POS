<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\SettingsService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * THE one place that works out profit, so the dashboard, the profit report and the daily closing can never disagree.
 *
 *   product sales   = what customers paid for goods, without tax, after every discount        (completed invoices, by invoice date)
 *   − sales returns = what was given back for returned goods, without tax                      (posted returns, by return date)
 *   service revenue = what delivered service jobs billed, without tax: charges AND parts        (delivered jobs, by invoice date)
 *   ------------------------------------------------------------------------------------------------------------------------------
 *   total revenue
 *   − cost of goods sold   = the cost on each invoice line, minus the cost of returned goods that went back on the shelf
 *   − service parts cost   = what the parts used on the delivered jobs cost the shop
 *   ------------------------------------------------------------------------------------------------------------------------------
 *   GROSS PROFIT
 *   − expenses             (recorded, by expense date)
 *   − stock lost or found  = stock adjustments at cost, except the opening stock
 *   − service parts written off (parts taken off a job and thrown away, by the day they were removed)
 *   ± difference on goods sent back to suppliers = credit received − cost of the stock that left
 *   ------------------------------------------------------------------------------------------------------------------------------
 *   NET PROFIT
 *
 * Cancelled invoices, cancelled returns, cancelled expenses and cancelled jobs never count. Tax is never revenue. Every cost is the cost
 * that was recorded when the thing happened, so a later change in the average cost does not rewrite history.
 * Nothing here checks permissions: whoever calls it must (profit is for `reports.profit` / `dashboard.financials`).
 */
class ProfitService
{
    public const GROUPS = Periods::GROUPS;

    /** Every figure that is added up per period; all are exact decimal strings with two decimals. */
    public const FIGURES = [
        'sales_revenue', 'sales_returns', 'cogs', 'cogs_returned', 'returns_written_off',
        'service_jobs', 'service_revenue', 'service_labour', 'service_parts_revenue', 'service_parts_cost',
        'expenses', 'stock_losses', 'service_written_off', 'supplier_difference', 'rounding',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, string> */
    public function summary(string $from, string $to): array
    {
        [$from, $to] = $this->range($from, $to);

        return $this->assemble($this->collect($from, $to, null)[''] ?? $this->zero());
    }

    /**
     * One row per day / week / month / year in the range (empty periods are listed too), each with the same figures as summary().
     *
     * @return list<array<string, string>> each row also has `period` (first day), `label` and `to` (last day of the period inside the range)
     */
    public function grouped(string $from, string $to, string $group): array
    {
        if (! isset(self::GROUPS[$group])) {
            throw new InvalidArgumentException("Unknown grouping [{$group}].");
        }
        [$from, $to] = $this->range($from, $to);
        $buckets = $this->collect($from, $to, $group);

        $rows = [];
        foreach (Periods::between($from, $to, $group) as $p) {
            $rows[] = $this->assemble($buckets[$p['key']] ?? $this->zero()) + ['period' => $p['from'], 'to' => $p['to'], 'label' => $p['label']];
        }

        return $rows;
    }

    /** The same figures for one day, used by the dashboard charts. @return array<string, array<string, string>> keyed by date */
    public function daily(string $from, string $to): array
    {
        $out = [];
        foreach ($this->grouped($from, $to, 'day') as $row) {
            $out[$row['period']] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ the arithmetic

    /** @param  array<string, string>  $f  the raw figures */
    private function assemble(array $f): array
    {
        $salesNet = Decimal::sub($f['sales_revenue'], $f['sales_returns'], 2);
        $cogsNet = Decimal::sub($f['cogs'], $f['cogs_returned'], 2);
        $revenue = Decimal::add($salesNet, $f['service_revenue'], 2);
        $cost = Decimal::add($cogsNet, $f['service_parts_cost'], 2);
        $gross = Decimal::sub($revenue, $cost, 2);
        $other = Decimal::add(Decimal::add($f['expenses'], $f['stock_losses'], 2), $f['service_written_off'], 2);
        // rounding differences on invoices (rounded up = the shop gained, rounded down = it gave away) belong to net profit, never to gross profit
        $net = Decimal::add(Decimal::add(Decimal::sub($gross, $other, 2), $f['supplier_difference'], 2), $f['rounding'], 2);

        return $f + [
            'sales_net' => $salesNet,
            'cogs_net' => $cogsNet,
            'product_profit' => Decimal::sub($salesNet, $cogsNet, 2),
            'service_profit' => Decimal::sub($f['service_revenue'], $f['service_parts_cost'], 2),
            'total_revenue' => $revenue,
            'total_cost' => $cost,
            'gross_profit' => $gross,
            'gross_margin' => Decimal::isPositive($revenue) ? Decimal::round(Decimal::mul(Decimal::div($gross, $revenue, 6), '100', 4), 1) : '0.0',
            'net_profit' => $net,
        ];
    }

    /** @return array<string, string> */
    private function zero(): array
    {
        return ['service_jobs' => '0'] + array_fill_keys(self::FIGURES, '0.00');
    }

    /**
     * Runs one grouped query per kind of figure and merges them by period.
     *
     * @return array<string, array<string, string>> bucket key (first day of the period, or '' for "everything") => raw figures
     */
    private function collect(string $from, string $to, ?string $group): array
    {
        $start = $from.' 00:00:00';
        $before = CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00';   // exclusive end for date-time columns
        $out = [];
        $add = function (string $bucket, string $figure, mixed $value) use (&$out) {
            $out[$bucket] ??= $this->zero();
            $out[$bucket][$figure] = $figure === 'service_jobs' ? (string) ((int) $out[$bucket][$figure] + (int) $value) : Decimal::add($out[$bucket][$figure], Decimal::round((string) ($value ?? 0), 2), 2);
        };
        $b = fn (string $col) => Periods::sql($col, $group);

        // product sales: revenue and the cost on each invoice line
        foreach (DB::select("SELECT {$b('s.sale_date')} AS b, COALESCE(SUM(i.net_amount), 0) AS revenue, COALESCE(SUM(ROUND(i.quantity * i.unit_cost, 2)), 0) AS cost
                               FROM sale_items i JOIN sales s ON s.id = i.sale_id
                              WHERE s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'sales_revenue', $r->revenue);
            $add((string) $r->b, 'cogs', $r->cost);
        }

        // returns: what was given back, the cost of the units that went back on the shelf, and the cost of those that did not (damaged or defective: a write-off,
        // already inside the cost of goods sold, so it is shown but never taken off twice)
        foreach (DB::select("SELECT {$b('r.return_date')} AS b, COALESCE(SUM(ri.net_amount), 0) AS revenue,
                                    COALESCE(SUM(CASE WHEN ri.restock = 1 THEN ROUND(ri.quantity * ri.unit_cost, 2) ELSE 0 END), 0) AS cost,
                                    COALESCE(SUM(CASE WHEN ri.restock = 0 THEN ROUND(ri.quantity * ri.unit_cost, 2) ELSE 0 END), 0) AS lost
                               FROM sale_return_items ri JOIN sale_returns r ON r.id = ri.sale_return_id
                              WHERE r.status = 'posted' AND r.return_date >= ? AND r.return_date < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'sales_returns', $r->revenue);
            $add((string) $r->b, 'cogs_returned', $r->cost);
            $add((string) $r->b, 'returns_written_off', $r->lost);
        }

        // rounding differences on invoices (the "round the total to" setting): kept apart from prices and costs, which are never changed
        foreach (DB::select("SELECT {$b('s.sale_date')} AS b, COALESCE(SUM(s.round_off), 0) AS diff
                               FROM sales s WHERE s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'rounding', $r->diff);
        }

        // restocking fees: revenue the shop kept out of returned goods, so it comes off what was given back
        foreach (DB::select("SELECT {$b('r.return_date')} AS b, COALESCE(SUM(r.restocking_fee), 0) AS fee
                               FROM sale_returns r WHERE r.status = 'posted' AND r.return_date >= ? AND r.return_date < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'sales_returns', Decimal::neg((string) $r->fee, 2));
        }

        // service jobs: what was billed (without tax) split into charges and parts in proportion, and what the parts cost
        foreach (DB::select("SELECT {$b('j.invoiced_at')} AS b, COUNT(*) AS jobs,
                                    COALESCE(SUM(j.grand_total - j.tax_amount), 0) AS revenue,
                                    COALESCE(SUM(ROUND((j.grand_total - j.tax_amount) * j.service_charges / NULLIF(j.service_charges + j.parts_amount, 0), 2)), 0) AS labour,
                                    COALESCE(SUM(j.parts_cost), 0) AS cost
                               FROM service_jobs j WHERE j.status = 'delivered' AND j.invoiced_at >= ? AND j.invoiced_at < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'service_jobs', $r->jobs);
            $add((string) $r->b, 'service_revenue', $r->revenue);
            $add((string) $r->b, 'service_labour', $r->labour);
            $add((string) $r->b, 'service_parts_revenue', Decimal::sub((string) $r->revenue, (string) $r->labour, 2));
            $add((string) $r->b, 'service_parts_cost', $r->cost);
        }

        foreach (DB::select("SELECT {$b('e.expense_date')} AS b, COALESCE(SUM(e.amount), 0) AS total
                               FROM expenses e WHERE e.status = 'posted' AND e.expense_date BETWEEN ? AND ? GROUP BY b", [$from, $to]) as $r) {
            $add((string) $r->b, 'expenses', $r->total);
        }

        // stock found (+) or lost (-) by adjustments, at cost; the opening stock is not a loss or a gain
        foreach (DB::select("SELECT {$b('m.created_at')} AS b, COALESCE(SUM(-ROUND(m.quantity * m.unit_cost, 2)), 0) AS lost
                               FROM stock_movements m LEFT JOIN stock_adjustments a ON m.source_type = 'stock_adjustment' AND a.id = m.source_id
                              WHERE m.movement_type IN ('adjustment_in', 'adjustment_out') AND (a.reason IS NULL OR a.reason <> 'opening')
                                AND m.created_at >= ? AND m.created_at < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'stock_losses', $r->lost);
        }

        foreach (DB::select("SELECT {$b('i.removed_at')} AS b, COALESCE(SUM(ROUND(i.quantity * i.unit_cost, 2)), 0) AS lost
                               FROM service_job_items i
                              WHERE i.line_type = 'part' AND i.removal_disposition = 'written_off' AND i.removed_at >= ? AND i.removed_at < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'service_written_off', $r->lost);
        }

        // goods sent back to a supplier: the credit received against the cost the stock left at
        $taxInCost = $this->settings->bool('tax.purchase_tax_in_cost') ? 'ri.tax_amount' : '0';
        foreach (DB::select("SELECT {$b('r.return_date')} AS b, COALESCE(SUM(ri.net_amount + {$taxInCost} - ROUND(ri.quantity * ri.unit_cost, 2)), 0) AS diff
                               FROM purchase_return_items ri JOIN purchase_returns r ON r.id = ri.purchase_return_id
                              WHERE r.status = 'posted' AND r.return_date >= ? AND r.return_date < ? GROUP BY b", [$start, $before]) as $r) {
            $add((string) $r->b, 'supplier_difference', $r->diff);
        }

        return $out;
    }

    /** @return array{0: string, 1: string} */
    private function range(string $from, string $to): array
    {
        try {
            $f = CarbonImmutable::parse($from)->toDateString();
            $t = CarbonImmutable::parse($to)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException('The dates are not valid.');
        }
        if ($f > $t) {
            throw new InvalidArgumentException('The "from" date is after the "to" date.');
        }
        if (CarbonImmutable::parse($f)->diffInDays(CarbonImmutable::parse($t)) > 3660) {
            throw new InvalidArgumentException('Please choose a period of at most ten years.');
        }

        return [$f, $t];
    }
}
