<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

use function route;

/** What is on the shelf and what it is worth, and what sells and what does not. */
class InventoryReports
{
    private function col(string $key, string $label, string $type = 'text', ?string $gate = null): array
    {
        return ReportResult::col($key, $label, $type, $gate);
    }

    /** Stock as it stands now (stock is not kept per day), valued at the average cost. */
    public function valuation(ReportFilters $f): ReportResult
    {
        $sql = "SELECT p.id, p.name, p.sku, p.current_stock, p.average_cost, p.sale_price, c.name AS category, u.short_name AS unit
                  FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN units u ON u.id = p.unit_id
                 WHERE p.type = 'stock' AND p.deleted_at IS NULL";
        $args = [];
        if ($f->str('status') !== 'all') {
            $sql .= ' AND p.current_stock <> 0';
        }
        if ($f->id('category_id')) {
            $sql .= ' AND p.category_id = ?';
            $args[] = $f->id('category_id');
        }

        // the totals are added up by the database (every product), the list shows the first 3,000 so a 20,000-product shop still gets its report quickly
        $where = substr($sql, strpos($sql, ' WHERE '));
        $sum = DB::selectOne('SELECT COALESCE(SUM(p.current_stock), 0) AS q, COALESCE(SUM(ROUND(p.current_stock * p.average_cost, 2)), 0) AS v, COALESCE(SUM(ROUND(p.current_stock * p.sale_price, 2)), 0) AS r, COUNT(*) AS n FROM products p'.$where, $args);

        $rows = [];
        $tot = ['product' => 'Total', 'quantity' => Decimal::round((string) $sum->q, 3), 'value' => Decimal::round((string) $sum->v, 2), 'retail' => Decimal::round((string) $sum->r, 2), 'margin' => Decimal::sub((string) $sum->r, (string) $sum->v, 2)];
        foreach (DB::select($sql.' ORDER BY c.name, p.name LIMIT 3000', $args) as $p) {
            $qty = Decimal::round((string) $p->current_stock, 3);
            $value = Decimal::mul($qty, (string) $p->average_cost, 2);
            $retail = Decimal::mul($qty, (string) $p->sale_price, 2);
            $rows[] = [
                'product' => $p->name, 'sku' => $p->sku, 'category' => $p->category, 'quantity' => $qty, 'unit' => $p->unit, 'average_cost' => Decimal::round((string) $p->average_cost, 4),
                'value' => $value, 'sale_price' => Decimal::round((string) $p->sale_price, 2), 'retail' => $retail, 'margin' => Decimal::sub($retail, $value, 2), '_href' => route('products.show', $p->id),
            ];
        }

        return (new ReportResult('Stock valuation', 'As at '.now()->format('d M Y, H:i')))->table(null, [
            $this->col('product', 'Product'), $this->col('sku', 'SKU'), $this->col('category', 'Category'), $this->col('quantity', 'In stock', 'qty'), $this->col('unit', 'Unit'),
            $this->col('average_cost', 'Average cost', 'money', 'products.view_cost'), $this->col('value', 'Value at cost', 'money', 'products.view_cost'),
            $this->col('sale_price', 'Sale price', 'money'), $this->col('retail', 'Value at sale price', 'money'), $this->col('margin', 'Margin if all sold', 'money', 'products.view_cost'),
        ], $rows, $tot)->note(((int) $sum->n > 3000 ? 'The list shows the first 3,000 of '.number_format((int) $sum->n).' products; the totals are for all of them. Choose a category to narrow it. ' : '').'Stock is valued at the average cost. Negative stock (sold before it was received) shows as a negative value.');
    }

    /** Best sellers of the period, and stock that has not sold at all in it. */
    public function movers(ReportFilters $f): ReportResult
    {
        $top = [];
        foreach (DB::select("SELECT p.id, p.name, p.sku, SUM(i.quantity) AS qty, SUM(i.net_amount) AS revenue, SUM(ROUND(i.quantity * i.unit_cost, 2)) AS cost
                               FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id
                              WHERE s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ? GROUP BY p.id, p.name, p.sku ORDER BY qty DESC, revenue DESC LIMIT 20", [$f->start(), $f->endExclusive()]) as $r) {
            $top[] = [
                'product' => $r->name, 'sku' => $r->sku, 'sold' => Decimal::round((string) $r->qty, 3), 'revenue' => Decimal::round((string) $r->revenue, 2),
                'profit' => Decimal::sub((string) $r->revenue, (string) $r->cost, 2), '_href' => route('products.show', $r->id),
            ];
        }

        $slow = [];
        foreach (DB::select("SELECT p.id, p.name, p.sku, p.current_stock, p.average_cost,
                                    (SELECT MAX(s.sale_date) FROM sale_items i JOIN sales s ON s.id = i.sale_id WHERE i.product_id = p.id AND s.status = 'completed') AS last_sold
                               FROM products p
                              WHERE p.type = 'stock' AND p.deleted_at IS NULL AND p.is_active = 1 AND p.current_stock > 0
                                AND NOT EXISTS (SELECT 1 FROM sale_items i JOIN sales s ON s.id = i.sale_id WHERE i.product_id = p.id AND s.status = 'completed' AND s.sale_date >= ? AND s.sale_date < ?)
                              ORDER BY (p.current_stock * p.average_cost) DESC, p.name LIMIT 100", [$f->start(), $f->endExclusive()]) as $r) {
            $slow[] = [
                'product' => $r->name, 'sku' => $r->sku, 'quantity' => Decimal::round((string) $r->current_stock, 3), 'value' => Decimal::mul((string) $r->current_stock, (string) $r->average_cost, 2),
                'last_sold' => $r->last_sold, '_href' => route('products.show', $r->id),
            ];
        }

        return (new ReportResult('Top sellers and slow movers', $f->label()))
            ->table('Top 20 sellers', [
                $this->col('product', 'Product'), $this->col('sku', 'SKU'), $this->col('sold', 'Sold', 'qty'), $this->col('revenue', 'Sales (without tax)', 'money'), $this->col('profit', 'Profit', 'money', 'reports.profit'),
            ], $top)
            ->table('Stock that did not sell in this period (biggest value first, up to 100)', [
                $this->col('product', 'Product'), $this->col('sku', 'SKU'), $this->col('quantity', 'In stock', 'qty'), $this->col('value', 'Value at cost', 'money', 'products.view_cost'), $this->col('last_sold', 'Last sold', 'datetime'),
            ], $slow);
    }
}
