<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

use function route;

/** Purchases and the goods sent back to suppliers, and what the shop owes its suppliers. */
class PurchaseReports
{
    private const LIMIT = 5000;

    private function col(string $key, string $label, string $type = 'text', ?string $gate = null): array
    {
        return ReportResult::col($key, $label, $type, $gate);
    }

    public function register(ReportFilters $f): ReportResult
    {
        $status = $f->str('status');
        $sql = "SELECT p.id, p.purchase_no, p.purchase_date, p.supplier_invoice_no, p.status, p.grand_total, p.paid_amount, p.returned_amount, p.due_amount, s.name AS supplier
                  FROM purchases p JOIN suppliers s ON s.id = p.supplier_id
                 WHERE p.status <> 'draft' AND p.purchase_date >= ? AND p.purchase_date < ?";
        $args = [$f->start(), $f->endExclusive()];
        if (in_array($status, ['received', 'voided'], true)) {
            $sql .= ' AND p.status = ?';
            $args[] = $status;
        }
        if ($f->id('supplier_id')) {
            $sql .= ' AND p.supplier_id = ?';
            $args[] = $f->id('supplier_id');
        }
        $found = DB::select($sql.' ORDER BY p.purchase_date, p.id LIMIT '.(self::LIMIT + 1), $args);

        $rows = [];
        $tot = ['purchase_no' => 'Total (not cancelled)', 'total' => '0.00', 'paid' => '0.00', 'returned' => '0.00', 'due' => '0.00'];
        foreach (array_slice($found, 0, self::LIMIT) as $r) {
            $rows[] = [
                'purchase_no' => $r->purchase_no, 'date' => $r->purchase_date, 'supplier' => $r->supplier, 'their_invoice' => $r->supplier_invoice_no,
                'total' => Decimal::round((string) $r->grand_total, 2), 'paid' => Decimal::round((string) $r->paid_amount, 2), 'returned' => Decimal::round((string) $r->returned_amount, 2),
                'due' => Decimal::round((string) $r->due_amount, 2), 'status' => $r->status === 'voided' ? 'Cancelled' : 'Received', '_href' => route('purchases.show', $r->id),
            ];
            if ($r->status === 'received') {
                foreach (['total' => 'grand_total', 'paid' => 'paid_amount', 'returned' => 'returned_amount', 'due' => 'due_amount'] as $k => $src) {
                    $tot[$k] = Decimal::add($tot[$k], (string) $r->{$src}, 2);
                }
            }
        }

        $result = (new ReportResult('Purchases and purchase returns', $f->label()))->table('Purchases received', [
            $this->col('purchase_no', 'Purchase'), $this->col('date', 'Date', 'date'), $this->col('supplier', 'Supplier'), $this->col('their_invoice', 'Their invoice'),
            $this->col('total', 'Total', 'money'), $this->col('paid', 'Paid', 'money'), $this->col('returned', 'Sent back', 'money'), $this->col('due', 'Still to pay', 'money'), $this->col('status', 'Status'),
        ], $rows, $tot);
        if (count($found) > self::LIMIT) {
            $result->note('Only the first '.number_format(self::LIMIT).' purchases are listed. Choose a shorter period to see the rest.');
        }

        // goods sent back in the same period
        $sql = 'SELECT r.id, r.return_no, r.return_date, r.reason, r.total, r.settlement, r.status, s.name AS supplier, p.purchase_no
                  FROM purchase_returns r JOIN suppliers s ON s.id = r.supplier_id LEFT JOIN purchases p ON p.id = r.purchase_id
                 WHERE r.return_date >= ? AND r.return_date < ?';
        $args = [$f->start(), $f->endExclusive()];
        if ($f->id('supplier_id')) {
            $sql .= ' AND r.supplier_id = ?';
            $args[] = $f->id('supplier_id');
        }
        $returnRows = [];
        $returnTotal = '0.00';
        foreach (DB::select($sql.' ORDER BY r.return_date, r.id', $args) as $r) {
            $returnRows[] = [
                'return_no' => $r->return_no, 'date' => $r->return_date, 'supplier' => $r->supplier, 'purchase' => $r->purchase_no ?? 'no bill', 'reason' => $r->reason,
                'total' => Decimal::round((string) $r->total, 2), 'settled' => $r->settlement === 'refund' ? 'Money received' : 'On account', 'status' => $r->status === 'voided' ? 'Cancelled' : 'Recorded',
                '_href' => route('purchase-returns.show', $r->id),
            ];
            if ($r->status === 'posted') {
                $returnTotal = Decimal::add($returnTotal, (string) $r->total, 2);
            }
        }
        $result->table('Goods sent back to suppliers', [
            $this->col('return_no', 'Return'), $this->col('date', 'Date', 'datetime'), $this->col('supplier', 'Supplier'), $this->col('purchase', 'Purchase'), $this->col('reason', 'Reason'),
            $this->col('total', 'Credit', 'money'), $this->col('settled', 'Settled'), $this->col('status', 'Status'),
        ], $returnRows, ['return_no' => 'Total (not cancelled)', 'total' => $returnTotal]);

        return $result;
    }
}
