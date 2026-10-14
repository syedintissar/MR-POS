<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

use function route;

/** Expenses, profit, and the closing summary of a day. */
class FinanceReports
{
    public function __construct(private readonly ProfitService $profit, private readonly SalesReports $sales) {}

    private function col(string $key, string $label, string $type = 'text', ?string $gate = null): array
    {
        return ReportResult::col($key, $label, $type, $gate);
    }

    // ------------------------------------------------------------------------------------------------ expenses

    public function expenses(ReportFilters $f): ReportResult
    {
        $status = $f->str('status') ?: 'posted';
        $where = 'e.expense_date >= ? AND e.expense_date < ?';
        $args = [$f->from, $f->endExclusive()];
        if (in_array($status, ['posted', 'voided'], true)) {
            $where .= ' AND e.status = ?';
            $args[] = $status;
        }
        if ($f->id('category_id')) {
            $where .= ' AND e.expense_category_id = ?';
            $args[] = $f->id('category_id');
        }
        if ($f->id('method_id')) {
            $where .= ' AND e.payment_method_id = ?';
            $args[] = $f->id('method_id');
        }

        $list = DB::select("SELECT e.id, e.expense_no, e.expense_date, e.payee, e.description, e.amount, e.status, c.name AS category, m.name AS method
                              FROM expenses e JOIN expense_categories c ON c.id = e.expense_category_id JOIN payment_methods m ON m.id = e.payment_method_id
                             WHERE {$where} ORDER BY e.expense_date, e.id", $args);

        $rows = [];
        $byCategory = [];
        $total = '0.00';
        foreach ($list as $e) {
            $rows[] = [
                'expense_no' => $e->expense_no, 'date' => $e->expense_date, 'category' => $e->category, 'payee' => $e->payee, 'description' => $e->description, 'method' => $e->method,
                'amount' => Decimal::round((string) $e->amount, 2), 'status' => $e->status === 'voided' ? 'Cancelled' : 'Recorded', '_href' => route('expenses.show', $e->id),
            ];
            if ($e->status === 'posted') {
                $byCategory[$e->category]['count'] = ($byCategory[$e->category]['count'] ?? 0) + 1;
                $byCategory[$e->category]['total'] = Decimal::add($byCategory[$e->category]['total'] ?? '0.00', (string) $e->amount, 2);
                $total = Decimal::add($total, (string) $e->amount, 2);
            }
        }
        uasort($byCategory, fn ($a, $b) => Decimal::cmp($b['total'], $a['total']));
        $catRows = [];
        foreach ($byCategory as $name => $c) {
            $catRows[] = ['category' => $name, 'count' => $c['count'], 'total' => $c['total'], 'share' => Decimal::isPositive($total) ? Decimal::round(Decimal::mul(Decimal::div($c['total'], $total, 6), '100', 4), 1) : null];
        }

        return (new ReportResult('Expenses', $f->label()))
            ->table('By category', [$this->col('category', 'Category'), $this->col('count', 'Entries', 'int'), $this->col('total', 'Total', 'money'), $this->col('share', 'Share', 'percent')],
                $catRows, ['category' => 'Total', 'count' => array_sum(array_column($catRows, 'count')), 'total' => $total, 'share' => null])
            ->table('Every expense', [
                $this->col('expense_no', 'No.'), $this->col('date', 'Date', 'date'), $this->col('category', 'Category'), $this->col('payee', 'Paid to'), $this->col('description', 'What for'),
                $this->col('method', 'Paid by'), $this->col('amount', 'Amount', 'money'), $this->col('status', 'Status'),
            ], $rows, ['expense_no' => 'Total (not cancelled)', 'amount' => $total]);
    }

    // ------------------------------------------------------------------------------------------------ profit

    public function profit(ReportFilters $f): ReportResult
    {
        $p = $this->profit->summary($f->from, $f->to);
        $line = fn (string $label, ?string $amount, string $note = '', bool $bold = false) => ['label' => $label, 'amount' => $amount, 'note' => $note, 'bold' => $bold];

        $statement = [
            $line('Product sales (without tax)', $p['sales_revenue']),
            $line('Less: goods returned by customers', Decimal::neg($p['sales_returns'], 2)),
            $line('Net product sales', $p['sales_net'], '', true),
            $line('Cost of goods sold', Decimal::neg($p['cogs_net'], 2), 'cost on the invoices, less the cost of returned goods put back on the shelf'),
            $line('  of which: returned goods that were not put back (damaged or defective), at cost', $p['returns_written_off'], 'already in the cost above, shown so the loss is visible'),
            $line('Profit on goods', $p['product_profit'], '', true),
            $line('Service jobs delivered', null, $p['service_jobs'].' job(s)'),
            $line('  Service charges (labour and other charges)', $p['service_labour']),
            $line('  Parts billed on service jobs', $p['service_parts_revenue']),
            $line('Service revenue (without tax, after job discounts)', $p['service_revenue'], '', true),
            $line('Cost of parts used on those jobs', Decimal::neg($p['service_parts_cost'], 2)),
            $line('Profit on service', $p['service_profit'], '', true),
            $line('TOTAL REVENUE', $p['total_revenue']),
            $line('TOTAL COST', Decimal::neg($p['total_cost'], 2)),
            $line('GROSS PROFIT', $p['gross_profit'], $p['gross_margin'].'% of revenue', true),
            $line('Expenses', Decimal::neg($p['expenses'], 2)),
            $line('Stock lost (or found) in adjustments', Decimal::neg($p['stock_losses'], 2), 'at cost; opening stock is not counted'),
            $line('Service parts written off', Decimal::neg($p['service_written_off'], 2), 'parts taken off a job and thrown away'),
            $line('Difference on goods sent back to suppliers', $p['supplier_difference'], 'credit received less the cost of the stock that left'),
            $line('Rounding differences on invoices', $p['rounding'], 'the shop gained when a total was rounded up and gave away when it was rounded down; prices and costs are never changed'),
            $line('NET PROFIT', $p['net_profit'], '', true),
        ];
        $periods = [];
        foreach ($this->profit->grouped($f->from, $f->to, $f->group) as $r) {
            $other = Decimal::sub(Decimal::sub(Decimal::add(Decimal::add($r['expenses'], $r['stock_losses'], 2), $r['service_written_off'], 2), $r['supplier_difference'], 2), $r['rounding'], 2);
            $periods[] = [
                'period' => $r['label'], 'product_sales' => $r['sales_net'], 'service' => $r['service_revenue'], 'revenue' => $r['total_revenue'], 'cost' => $r['total_cost'],
                'gross' => $r['gross_profit'], 'other' => $other, 'net' => $r['net_profit'],
            ];
        }

        return (new ReportResult('Profit', $f->label()))
            ->table('Profit and loss', [$this->col('label', 'Line'), $this->col('amount', 'Amount', 'money'), $this->col('note', 'Note')], $statement)
            ->table('By '.strtolower(Periods::GROUPS[$f->group]), [
                $this->col('period', Periods::GROUPS[$f->group]), $this->col('product_sales', 'Net product sales', 'money'), $this->col('service', 'Service revenue', 'money'), $this->col('revenue', 'Total revenue', 'money'),
                $this->col('cost', 'Cost', 'money'), $this->col('gross', 'Gross profit', 'money'), $this->col('other', 'Expenses and losses', 'money'), $this->col('net', 'Net profit', 'money'),
            ], $periods, [
                'period' => 'Total', 'product_sales' => $p['sales_net'], 'service' => $p['service_revenue'], 'revenue' => $p['total_revenue'], 'cost' => $p['total_cost'], 'gross' => $p['gross_profit'],
                'other' => Decimal::sub(Decimal::sub(Decimal::add(Decimal::add($p['expenses'], $p['stock_losses'], 2), $p['service_written_off'], 2), $p['supplier_difference'], 2), $p['rounding'], 2), 'net' => $p['net_profit'],
            ])
            ->note('Cancelled invoices, returns, expenses and jobs never count. Tax is never revenue. Each cost is the cost recorded when the sale or job happened. Service jobs count on the day they were delivered and invoiced.');
    }

    // ------------------------------------------------------------------------------------------------ closing

    public function closing(ReportFilters $f): ReportResult
    {
        $s = DB::selectOne("SELECT COUNT(*) AS invoices, COALESCE(SUM(grand_total), 0) AS total, COALESCE(SUM(tax_total), 0) AS tax, COALESCE(SUM(line_discount_total + invoice_discount_amount), 0) AS discounts
                              FROM sales WHERE status = 'completed' AND sale_date >= ? AND sale_date < ?", [$f->start(), $f->endExclusive()]);
        $cancelled = (int) DB::selectOne("SELECT COUNT(*) AS n FROM sales WHERE status = 'voided' AND sale_date >= ? AND sale_date < ?", [$f->start(), $f->endExclusive()])->n;
        $returns = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(total - restocking_fee), 0) AS total FROM sale_returns WHERE status = 'posted' AND return_date >= ? AND return_date < ?", [$f->start(), $f->endExclusive()]);
        $jobs = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS total FROM service_jobs WHERE status = 'delivered' AND invoiced_at >= ? AND invoiced_at < ?", [$f->start(), $f->endExclusive()]);
        $purchases = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS total FROM purchases WHERE status = 'received' AND purchase_date >= ? AND purchase_date < ?", [$f->start(), $f->endExclusive()]);
        $expenses = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM expenses WHERE status = 'posted' AND expense_date >= ? AND expense_date < ?", [$f->from, $f->endExclusive()]);

        $r = fn (string $label, mixed $count, mixed $amount) => ['label' => $label, 'count' => $count, 'amount' => $amount === null ? null : Decimal::round((string) $amount, 2)];
        $summary = [
            $r('Invoices (sales)', (int) $s->invoices, $s->total),
            $r('  of which tax', null, $s->tax),
            $r('  discounts given', null, $s->discounts),
            $r('Cancelled invoices', $cancelled, null),
            $r('Goods returned by customers', (int) $returns->n, $returns->total),
            $r('Service jobs delivered', (int) $jobs->n, $jobs->total),
            $r('Purchases received', (int) $purchases->n, $purchases->total),
        ];

        $result = (new ReportResult('Closing summary', $f->label()))
            ->table('The day in numbers', [$this->col('label', 'What'), $this->col('count', 'Count', 'int'), $this->col('amount', 'Amount', 'money')], $summary);

        $money = $this->sales->payments($f)->tables[0];
        $result->table('Money in and out by payment method', $money['columns'], $money['rows'], $money['totals']);

        $p = $this->profit->summary($f->from, $f->to);
        $result->table('Profit for the period', [$this->col('label', 'Line', 'text', 'reports.profit'), $this->col('amount', 'Amount', 'money', 'reports.profit')], [
            ['label' => 'Revenue (goods and services, without tax)', 'amount' => $p['total_revenue']],
            ['label' => 'Cost of goods and parts', 'amount' => Decimal::neg($p['total_cost'], 2)],
            ['label' => 'Gross profit', 'amount' => $p['gross_profit']],
            ['label' => 'Net profit (after expenses and losses)', 'amount' => $p['net_profit']],
        ]);

        $result->table('Expenses', [$this->col('label', 'What', 'text', 'expenses.view'), $this->col('count', 'Count', 'int', 'expenses.view'), $this->col('amount', 'Amount', 'money', 'expenses.view')], [$r('Expenses recorded', (int) $expenses->n, $expenses->total)]);

        return $result->note('Count the cash in the drawer against the "Net movement" of the Cash row. Cancelled invoices are listed so that none disappears unnoticed.');
    }
}
