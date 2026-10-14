<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

use function route;

/** Sales summary and register, sales by product / category / customer / cashier, sales returns, and money in and out by payment method. */
class SalesReports
{
    private const LIMIT = 5000;

    private function col(string $key, string $label, string $type = 'text', ?string $gate = null): array
    {
        return ReportResult::col($key, $label, $type, $gate);
    }

    // ------------------------------------------------------------------------------------------------ RPT-01

    public function summary(ReportFilters $f): ReportResult
    {
        $b = Periods::sql('s.sale_date', $f->group);
        $sales = [];
        foreach (DB::select("SELECT {$b} AS b, COUNT(*) AS invoices, SUM(s.items_total) AS gross, SUM(s.line_discount_total + s.invoice_discount_amount) AS discounts,
                                    SUM(s.tax_total) AS tax, SUM(s.grand_total) AS total
                               FROM sales s WHERE s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ? GROUP BY b", [$f->start(), $f->endExclusive()]) as $r) {
            $sales[(string) $r->b] = $r;
        }
        $returns = [];
        $rb = Periods::sql('r.return_date', $f->group);
        foreach (DB::select("SELECT {$rb} AS b, SUM(r.total - r.restocking_fee) AS total FROM sale_returns r WHERE r.status = 'posted' AND r.return_date >= ? AND r.return_date < ? GROUP BY b", [$f->start(), $f->endExclusive()]) as $r) {
            $returns[(string) $r->b] = (string) $r->total;
        }

        $rows = [];
        $tot = ['invoices' => 0, 'gross' => '0.00', 'discounts' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'returns' => '0.00', 'net' => '0.00'];
        foreach (Periods::between($f->from, $f->to, $f->group) as $p) {
            $s = $sales[$p['key']] ?? null;
            $ret = $returns[$p['key']] ?? '0.00';
            $row = [
                'period' => $p['label'], 'invoices' => (int) ($s->invoices ?? 0), 'gross' => Decimal::round((string) ($s->gross ?? 0), 2), 'discounts' => Decimal::round((string) ($s->discounts ?? 0), 2),
                'tax' => Decimal::round((string) ($s->tax ?? 0), 2), 'total' => Decimal::round((string) ($s->total ?? 0), 2), 'returns' => Decimal::round($ret, 2),
            ];
            $row['net'] = Decimal::sub($row['total'], $row['returns'], 2);
            $rows[] = $row;
            $tot['invoices'] += $row['invoices'];
            foreach (['gross', 'discounts', 'tax', 'total', 'returns', 'net'] as $k) {
                $tot[$k] = Decimal::add($tot[$k], $row[$k], 2);
            }
        }
        $tot['period'] = 'Total';

        return (new ReportResult('Sales summary', $f->label()))
            ->table(null, [
                $this->col('period', Periods::GROUPS[$f->group]), $this->col('invoices', 'Invoices', 'int'), $this->col('gross', 'Goods and services', 'money'), $this->col('discounts', 'Discounts', 'money'),
                $this->col('tax', 'Tax', 'money'), $this->col('total', 'Invoiced', 'money'), $this->col('returns', 'Returned', 'money'), $this->col('net', 'After returns', 'money'),
            ], $rows, $tot)
            ->note('Cancelled invoices are left out. "Invoiced" includes tax; returns are counted on the day the goods came back.');
    }

    // ------------------------------------------------------------------------------------------------ RPT-02

    public function register(ReportFilters $f): ReportResult
    {
        $status = $f->str('status');
        $sql = 'SELECT s.id, s.invoice_no, s.sale_date, s.status, s.grand_total, s.paid_amount, s.returned_amount, s.due_amount, c.name AS customer, c.is_walk_in, u.name AS cashier,
                       (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.id) AS items
                  FROM sales s JOIN customers c ON c.id = s.customer_id LEFT JOIN users u ON u.id = s.user_id
                 WHERE s.sale_date >= ? AND s.sale_date < ?';
        $args = [$f->start(), $f->endExclusive()];
        if (in_array($status, ['completed', 'voided'], true)) {
            $sql .= ' AND s.status = ?';
            $args[] = $status;
        }
        if ($f->id('customer_id')) {
            $sql .= ' AND s.customer_id = ?';
            $args[] = $f->id('customer_id');
        }
        if ($f->id('user_id')) {
            $sql .= ' AND s.user_id = ?';
            $args[] = $f->id('user_id');
        }
        $found = DB::select($sql.' ORDER BY s.sale_date, s.id LIMIT '.(self::LIMIT + 1), $args);

        $rows = [];
        $tot = ['invoice_no' => 'Total (completed)', 'total' => '0.00', 'paid' => '0.00', 'returned' => '0.00', 'due' => '0.00'];
        foreach (array_slice($found, 0, self::LIMIT) as $r) {
            $rows[] = [
                'invoice_no' => $r->invoice_no, 'date' => $r->sale_date, 'customer' => $r->is_walk_in ? 'Walk-in customer' : $r->customer, 'cashier' => $r->cashier, 'items' => (int) $r->items,
                'total' => Decimal::round((string) $r->grand_total, 2), 'paid' => Decimal::round((string) $r->paid_amount, 2), 'returned' => Decimal::round((string) $r->returned_amount, 2),
                'due' => Decimal::round((string) $r->due_amount, 2), 'status' => $r->status === 'voided' ? 'Cancelled' : 'Completed', '_href' => route('sales.show', $r->id),
            ];
            if ($r->status === 'completed') {
                foreach (['total' => 'grand_total', 'paid' => 'paid_amount', 'returned' => 'returned_amount', 'due' => 'due_amount'] as $k => $src) {
                    $tot[$k] = Decimal::add($tot[$k], (string) $r->{$src}, 2);
                }
            }
        }

        $result = (new ReportResult('Sales register', $f->label()))->table(null, [
            $this->col('invoice_no', 'Invoice'), $this->col('date', 'Date', 'datetime'), $this->col('customer', 'Customer'), $this->col('cashier', 'Cashier'), $this->col('items', 'Lines', 'int'),
            $this->col('total', 'Total', 'money'), $this->col('paid', 'Paid', 'money'), $this->col('returned', 'Returned', 'money'), $this->col('due', 'Still owed', 'money'), $this->col('status', 'Status'),
        ], $rows, $tot);
        if (count($found) > self::LIMIT) {
            $result->note('Only the first '.number_format(self::LIMIT).' invoices are listed. Choose a shorter period to see the rest.');
        }

        return $result;
    }

    // ------------------------------------------------------------------------------------------------ RPT-03 / RPT-04

    public function byProduct(ReportFilters $f): ReportResult
    {
        $rows = [];
        $tot = ['product' => 'Total', 'sold' => '0.000', 'returned' => '0.000', 'revenue' => '0.00', 'cost' => '0.00', 'profit' => '0.00'];
        foreach ($this->productFigures($f) as $p) {
            $rows[] = $this->figureRow($p, $p['name'], ['sku' => $p['sku'], 'category' => $p['category']]);
            $tot = $this->addToTotal($tot, $p);
        }
        $tot['margin'] = Decimal::isPositive($tot['revenue']) ? Decimal::round(Decimal::mul(Decimal::div($tot['profit'], $tot['revenue'], 6), '100', 4), 1) : null;
        usort($rows, fn ($a, $b) => Decimal::cmp($b['revenue'], $a['revenue']) ?: strcmp($a['product'], $b['product']));

        return (new ReportResult('Sales by product', $f->label()))->table(null, [
            $this->col('product', 'Product'), $this->col('sku', 'SKU'), $this->col('category', 'Category'), $this->col('sold', 'Sold', 'qty'), $this->col('returned', 'Returned', 'qty'),
            $this->col('revenue', 'Sales (without tax, after returns)', 'money'), $this->col('cost', 'Cost', 'money', 'products.view_cost'),
            $this->col('profit', 'Profit', 'money', 'reports.profit'), $this->col('margin', 'Margin', 'percent', 'reports.profit'),
        ], $rows, $tot)->note('Cancelled invoices are left out. Returns are taken off on the day the goods came back; goods put back on the shelf also take their cost off.');
    }

    public function byCategory(ReportFilters $f): ReportResult
    {
        $groups = [];
        foreach ($this->productFigures($f) as $p) {
            $key = $p['category'] ?: 'No category';
            $groups[$key] ??= ['name' => $key, 'sold' => '0.000', 'returned' => '0.000', 'revenue' => '0.00', 'cost' => '0.00'];
            $groups[$key]['sold'] = Decimal::add($groups[$key]['sold'], $p['sold'], 3);
            $groups[$key]['returned'] = Decimal::add($groups[$key]['returned'], $p['returned'], 3);
            $groups[$key]['revenue'] = Decimal::add($groups[$key]['revenue'], $p['revenue'], 2);
            $groups[$key]['cost'] = Decimal::add($groups[$key]['cost'], $p['cost'], 2);
        }
        $rows = [];
        $tot = ['product' => 'Total', 'sold' => '0.000', 'returned' => '0.000', 'revenue' => '0.00', 'cost' => '0.00', 'profit' => '0.00'];
        foreach ($groups as $g) {
            $rows[] = $this->figureRow($g, $g['name']);
            $tot = $this->addToTotal($tot, $g);
        }
        $tot['margin'] = Decimal::isPositive($tot['revenue']) ? Decimal::round(Decimal::mul(Decimal::div($tot['profit'], $tot['revenue'], 6), '100', 4), 1) : null;
        usort($rows, fn ($a, $b) => Decimal::cmp($b['revenue'], $a['revenue']) ?: strcmp($a['product'], $b['product']));

        return (new ReportResult('Sales by category', $f->label()))->table(null, [
            $this->col('product', 'Category'), $this->col('sold', 'Sold', 'qty'), $this->col('returned', 'Returned', 'qty'),
            $this->col('revenue', 'Sales (without tax, after returns)', 'money'), $this->col('cost', 'Cost', 'money', 'products.view_cost'),
            $this->col('profit', 'Profit', 'money', 'reports.profit'), $this->col('margin', 'Margin', 'percent', 'reports.profit'),
        ], $rows, $tot);
    }

    /**
     * Sold and returned figures for every product that moved in the period.
     *
     * @return array<int, array{id: int, name: string, sku: string, category: ?string, sold: string, returned: string, revenue: string, cost: string}>
     */
    private function productFigures(ReportFilters $f): array
    {
        $category = $f->id('category_id');
        $catSql = $category ? ' AND p.category_id = ?' : '';
        $figures = [];

        foreach (DB::select("SELECT p.id, p.name, p.sku, c.name AS category, SUM(i.quantity) AS qty, SUM(i.net_amount) AS revenue, SUM(ROUND(i.quantity * i.unit_cost, 2)) AS cost
                               FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id LEFT JOIN categories c ON c.id = p.category_id
                              WHERE s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ?{$catSql} GROUP BY p.id, p.name, p.sku, c.name",
            array_values(array_filter([$f->start(), $f->endExclusive(), $category]))) as $r) {
            $figures[$r->id] = ['id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku, 'category' => $r->category, 'sold' => Decimal::round((string) $r->qty, 3), 'returned' => '0.000',
                'revenue' => Decimal::round((string) $r->revenue, 2), 'cost' => Decimal::round((string) $r->cost, 2)];
        }

        foreach (DB::select("SELECT p.id, p.name, p.sku, c.name AS category, SUM(ri.quantity) AS qty, SUM(ri.net_amount) AS revenue,
                                    SUM(CASE WHEN ri.restock = 1 THEN ROUND(ri.quantity * ri.unit_cost, 2) ELSE 0 END) AS cost
                               FROM sale_return_items ri JOIN sale_returns r ON r.id = ri.sale_return_id JOIN products p ON p.id = ri.product_id LEFT JOIN categories c ON c.id = p.category_id
                              WHERE r.status = 'posted' AND r.return_date >= ? AND r.return_date < ?{$catSql} GROUP BY p.id, p.name, p.sku, c.name",
            array_values(array_filter([$f->start(), $f->endExclusive(), $category]))) as $r) {
            $figures[$r->id] ??= ['id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku, 'category' => $r->category, 'sold' => '0.000', 'returned' => '0.000', 'revenue' => '0.00', 'cost' => '0.00'];
            $figures[$r->id]['returned'] = Decimal::round((string) $r->qty, 3);
            $figures[$r->id]['revenue'] = Decimal::sub($figures[$r->id]['revenue'], (string) $r->revenue, 2);
            $figures[$r->id]['cost'] = Decimal::sub($figures[$r->id]['cost'], (string) $r->cost, 2);
        }

        return $figures;
    }

    /** @param  array<string, mixed>  $p */
    private function figureRow(array $p, string $name, array $more = []): array
    {
        $profit = Decimal::sub($p['revenue'], $p['cost'], 2);

        return ['product' => $name] + $more + [
            'sold' => $p['sold'], 'returned' => $p['returned'], 'revenue' => $p['revenue'], 'cost' => $p['cost'], 'profit' => $profit,
            'margin' => Decimal::isPositive($p['revenue']) ? Decimal::round(Decimal::mul(Decimal::div($profit, $p['revenue'], 6), '100', 4), 1) : null,
        ];
    }

    /** @param  array<string, mixed>  $tot @param  array<string, mixed>  $p */
    private function addToTotal(array $tot, array $p): array
    {
        $tot['sold'] = Decimal::add($tot['sold'], $p['sold'], 3);
        $tot['returned'] = Decimal::add($tot['returned'], $p['returned'], 3);
        $tot['revenue'] = Decimal::add($tot['revenue'], $p['revenue'], 2);
        $tot['cost'] = Decimal::add($tot['cost'], $p['cost'], 2);
        $tot['profit'] = Decimal::sub($tot['revenue'], $tot['cost'], 2);

        return $tot;
    }

    // ------------------------------------------------------------------------------------------------ RPT-05

    public function byCustomer(ReportFilters $f): ReportResult
    {
        $groups = [];
        foreach (DB::select("SELECT c.id, c.name, c.is_walk_in, COUNT(*) AS invoices, SUM(s.grand_total) AS total, SUM(s.due_amount) AS due
                               FROM sales s JOIN customers c ON c.id = s.customer_id
                              WHERE s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ? GROUP BY c.id, c.name, c.is_walk_in", [$f->start(), $f->endExclusive()]) as $r) {
            $groups[$r->id] = ['customer' => $r->is_walk_in ? 'Walk-in customers' : $r->name, 'invoices' => (int) $r->invoices, 'total' => Decimal::round((string) $r->total, 2), 'returns' => '0.00', 'due' => Decimal::round((string) $r->due, 2)];
        }
        foreach (DB::select("SELECT c.id, c.name, c.is_walk_in, SUM(r.total - r.restocking_fee) AS total
                               FROM sale_returns r JOIN customers c ON c.id = r.customer_id
                              WHERE r.status = 'posted' AND r.return_date >= ? AND r.return_date < ? GROUP BY c.id, c.name, c.is_walk_in", [$f->start(), $f->endExclusive()]) as $r) {
            $groups[$r->id] ??= ['customer' => $r->is_walk_in ? 'Walk-in customers' : $r->name, 'invoices' => 0, 'total' => '0.00', 'returns' => '0.00', 'due' => '0.00'];
            $groups[$r->id]['returns'] = Decimal::round((string) $r->total, 2);
        }

        $rows = [];
        $tot = ['customer' => 'Total', 'invoices' => 0, 'total' => '0.00', 'returns' => '0.00', 'net' => '0.00', 'due' => '0.00'];
        foreach ($groups as $g) {
            $g['net'] = Decimal::sub($g['total'], $g['returns'], 2);
            $rows[] = $g;
            $tot['invoices'] += $g['invoices'];
            foreach (['total', 'returns', 'net', 'due'] as $k) {
                $tot[$k] = Decimal::add($tot[$k], $g[$k], 2);
            }
        }
        usort($rows, fn ($a, $b) => Decimal::cmp($b['net'], $a['net']) ?: strcmp($a['customer'], $b['customer']));

        return (new ReportResult('Sales by customer', $f->label()))->table(null, [
            $this->col('customer', 'Customer'), $this->col('invoices', 'Invoices', 'int'), $this->col('total', 'Invoiced', 'money'), $this->col('returns', 'Returned', 'money'),
            $this->col('net', 'After returns', 'money'), $this->col('due', 'Still owed on these invoices', 'money'),
        ], $rows, $tot);
    }

    // ------------------------------------------------------------------------------------------------ RPT-06

    public function byCashier(ReportFilters $f): ReportResult
    {
        $groups = [];
        $blank = fn (string $name) => ['cashier' => $name, 'invoices' => 0, 'total' => '0.00', 'average' => '0.00', 'discounts' => '0.00', 'cancelled' => 0, 'returns' => '0.00'];
        foreach (DB::select("SELECT s.user_id AS id, u.name, SUM(s.status = 'completed') AS invoices, SUM(CASE WHEN s.status = 'completed' THEN s.grand_total ELSE 0 END) AS total,
                                    SUM(CASE WHEN s.status = 'completed' THEN s.line_discount_total + s.invoice_discount_amount ELSE 0 END) AS discounts, SUM(s.status = 'voided') AS cancelled
                               FROM sales s LEFT JOIN users u ON u.id = s.user_id WHERE s.sale_date >= ? AND s.sale_date < ? GROUP BY s.user_id, u.name", [$f->start(), $f->endExclusive()]) as $r) {
            $g = $blank($r->name ?? 'Unknown');
            $g['invoices'] = (int) $r->invoices;
            $g['total'] = Decimal::round((string) $r->total, 2);
            $g['discounts'] = Decimal::round((string) $r->discounts, 2);
            $g['cancelled'] = (int) $r->cancelled;
            $g['average'] = $g['invoices'] > 0 ? Decimal::div($g['total'], (string) $g['invoices'], 2) : '0.00';
            $groups[(int) $r->id] = $g;
        }
        foreach (DB::select("SELECT r.created_by AS id, u.name, SUM(r.total - r.restocking_fee) AS total FROM sale_returns r LEFT JOIN users u ON u.id = r.created_by
                              WHERE r.status = 'posted' AND r.return_date >= ? AND r.return_date < ? GROUP BY r.created_by, u.name", [$f->start(), $f->endExclusive()]) as $r) {
            $groups[(int) $r->id] ??= $blank($r->name ?? 'Unknown');
            $groups[(int) $r->id]['returns'] = Decimal::round((string) $r->total, 2);
        }

        $rows = array_values($groups);
        usort($rows, fn ($a, $b) => Decimal::cmp($b['total'], $a['total']) ?: strcmp($a['cashier'], $b['cashier']));
        $tot = ['cashier' => 'Total', 'invoices' => array_sum(array_column($rows, 'invoices')), 'cancelled' => array_sum(array_column($rows, 'cancelled'))];
        foreach (['total', 'discounts', 'returns'] as $k) {
            $tot[$k] = array_reduce($rows, fn ($c, $r) => Decimal::add($c, $r[$k], 2), '0.00');
        }
        $tot['average'] = $tot['invoices'] > 0 ? Decimal::div($tot['total'], (string) $tot['invoices'], 2) : '0.00';

        return (new ReportResult('Sales by cashier', $f->label()))->table(null, [
            $this->col('cashier', 'Cashier'), $this->col('invoices', 'Invoices', 'int'), $this->col('total', 'Invoiced', 'money'), $this->col('average', 'Average invoice', 'money'),
            $this->col('discounts', 'Discounts given', 'money'), $this->col('cancelled', 'Cancelled invoices', 'int'), $this->col('returns', 'Returns taken', 'money'),
        ], $rows, $tot)->note('Cancelled invoices and big discounts are the first things to look at when the till and the books disagree.');
    }

    // ------------------------------------------------------------------------------------------------ RPT-07

    public function returns(ReportFilters $f): ReportResult
    {
        $status = $f->str('status');
        $sql = 'SELECT r.id, r.return_no, r.return_date, r.reason, r.total, r.restocking_fee, r.applied_to_due, r.refund_amount, r.store_credit_amount, r.status, s.invoice_no, c.name AS customer, c.is_walk_in,
                       (SELECT COALESCE(SUM(ri.quantity), 0) FROM sale_return_items ri WHERE ri.sale_return_id = r.id) AS units
                  FROM sale_returns r JOIN sales s ON s.id = r.sale_id JOIN customers c ON c.id = r.customer_id
                 WHERE r.return_date >= ? AND r.return_date < ?';
        $args = [$f->start(), $f->endExclusive()];
        if (in_array($status, ['posted', 'voided'], true)) {
            $sql .= ' AND r.status = ?';
            $args[] = $status;
        }
        if ($f->id('customer_id')) {
            $sql .= ' AND r.customer_id = ?';
            $args[] = $f->id('customer_id');
        }

        $rows = [];
        $tot = ['return_no' => 'Total (not cancelled)', 'units' => '0.000', 'total' => '0.00', 'fee' => '0.00', 'applied' => '0.00', 'refund' => '0.00', 'credit' => '0.00'];
        foreach (DB::select($sql.' ORDER BY r.return_date, r.id', $args) as $r) {
            $rows[] = [
                'return_no' => $r->return_no, 'date' => $r->return_date, 'invoice' => $r->invoice_no, 'customer' => $r->is_walk_in ? 'Walk-in customer' : $r->customer, 'reason' => $r->reason,
                'units' => Decimal::round((string) $r->units, 3), 'total' => Decimal::round((string) $r->total, 2), 'fee' => Decimal::round((string) $r->restocking_fee, 2), 'applied' => Decimal::round((string) $r->applied_to_due, 2),
                'refund' => Decimal::round((string) $r->refund_amount, 2), 'credit' => Decimal::round((string) $r->store_credit_amount, 2), 'status' => $r->status === 'voided' ? 'Cancelled' : 'Recorded',
                '_href' => route('sale-returns.show', $r->id),
            ];
            if ($r->status === 'posted') {
                $tot['units'] = Decimal::add($tot['units'], (string) $r->units, 3);
                foreach (['total' => 'total', 'fee' => 'restocking_fee', 'applied' => 'applied_to_due', 'refund' => 'refund_amount', 'credit' => 'store_credit_amount'] as $k => $src) {
                    $tot[$k] = Decimal::add($tot[$k], (string) $r->{$src}, 2);
                }
            }
        }

        return (new ReportResult('Sales returns', $f->label()))->table(null, [
            $this->col('return_no', 'Return'), $this->col('date', 'Date', 'datetime'), $this->col('invoice', 'Invoice'), $this->col('customer', 'Customer'), $this->col('reason', 'Reason'),
            $this->col('units', 'Units', 'qty'), $this->col('total', 'Value', 'money'), $this->col('fee', 'Fee kept', 'money'), $this->col('applied', 'Off the debt', 'money'), $this->col('refund', 'Money back', 'money'),
            $this->col('credit', 'Store credit', 'money'), $this->col('status', 'Status'),
        ], $rows, $tot);
    }

    // ------------------------------------------------------------------------------------------------ RPT-08

    /** Money in and out of the till and the bank, by payment method: customers, suppliers and expenses. */
    public function payments(ReportFilters $f): ReportResult
    {
        $methods = DB::select("SELECT id, name FROM payment_methods WHERE kind <> 'customer_credit' ORDER BY sort_order, name");
        $figures = [];
        $touch = function (int $id, string $key, mixed $value) use (&$figures) {
            $figures[$id][$key] = Decimal::add($figures[$id][$key] ?? '0.00', Decimal::round((string) ($value ?? 0), 2), 2);
        };

        foreach (DB::select("SELECT cp.payment_method_id AS id, SUM(CASE WHEN cp.type = 'receipt' THEN cp.amount ELSE 0 END) AS received, SUM(CASE WHEN cp.type = 'refund' THEN cp.amount ELSE 0 END) AS refunded
                               FROM customer_payments cp WHERE cp.status = 'posted' AND cp.payment_date >= ? AND cp.payment_date < ? GROUP BY cp.payment_method_id", [$f->start(), $f->endExclusive()]) as $r) {
            $touch((int) $r->id, 'received', $r->received);
            $touch((int) $r->id, 'refunded', $r->refunded);
        }
        foreach (DB::select("SELECT sp.payment_method_id AS id, SUM(CASE WHEN sp.type = 'payment' THEN sp.amount ELSE 0 END) AS paid, SUM(CASE WHEN sp.type = 'refund' THEN sp.amount ELSE 0 END) AS back
                               FROM supplier_payments sp WHERE sp.status = 'posted' AND sp.payment_date >= ? AND sp.payment_date < ? GROUP BY sp.payment_method_id", [$f->start(), $f->endExclusive()]) as $r) {
            $touch((int) $r->id, 'suppliers_paid', $r->paid);
            $touch((int) $r->id, 'suppliers_back', $r->back);
        }
        foreach (DB::select("SELECT e.payment_method_id AS id, SUM(e.amount) AS total FROM expenses e WHERE e.status = 'posted' AND e.expense_date >= ? AND e.expense_date < ? GROUP BY e.payment_method_id", [$f->start(), $f->endExclusive()]) as $r) {
            $touch((int) $r->id, 'expenses', $r->total);
        }

        $rows = [];
        $keys = ['received', 'refunded', 'suppliers_paid', 'suppliers_back', 'expenses'];
        $tot = array_fill_keys($keys, '0.00') + ['method' => 'Total', 'net' => '0.00'];
        foreach ($methods as $m) {
            $x = ($figures[$m->id] ?? []) + array_fill_keys($keys, '0.00');
            $net = Decimal::sub(Decimal::add(Decimal::add($x['received'], $x['suppliers_back'], 2), '0', 2), Decimal::add(Decimal::add($x['refunded'], $x['suppliers_paid'], 2), $x['expenses'], 2), 2);
            if (array_filter($x, fn ($v) => ! Decimal::isZero($v)) === []) {
                continue;
            }
            $rows[] = ['method' => $m->name] + array_intersect_key($x, array_flip($keys)) + ['net' => $net];
            foreach ($keys as $k) {
                $tot[$k] = Decimal::add($tot[$k], $x[$k], 2);
            }
            $tot['net'] = Decimal::add($tot['net'], $net, 2);
        }

        return (new ReportResult('Money in and out by payment method', $f->label()))->table(null, [
            $this->col('method', 'Method'), $this->col('received', 'Received from customers', 'money'), $this->col('suppliers_back', 'Received from suppliers', 'money'),
            $this->col('refunded', 'Refunded to customers', 'money'), $this->col('suppliers_paid', 'Paid to suppliers', 'money'), $this->col('expenses', 'Expenses paid', 'money', 'expenses.view'),
            $this->col('net', 'Net movement', 'money', 'expenses.view'),
        ], $rows, $tot)->note('Customer credit is not money, so it is not listed. Cash sales are shown after change was given back.');
    }
}
