<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Customers\ReceivablesService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

use function route;

/** Who owes the shop (aged), and whom the shop owes (aged). */
class PeopleReports
{
    public function __construct(private readonly ReceivablesService $receivables) {}

    private function col(string $key, string $label, string $type = 'text', ?string $gate = null): array
    {
        return ReportResult::col($key, $label, $type, $gate);
    }

    public function customerBalances(ReportFilters $f): ReportResult
    {
        $data = $this->receivables->report($f->id('customer_id'));
        $rows = [];
        foreach ($data['rows'] as $r) {
            $c = $r['customer'];
            $rows[] = ['customer' => $c->name, 'phone' => $c->phone] + $r['buckets'] + ['other' => $r['other'], 'balance' => $r['balance'], '_href' => route('customers.show', $c->id)];
        }
        usort($rows, fn ($a, $b) => Decimal::cmp($b['balance'], $a['balance']) ?: strcmp($a['customer'], $b['customer']));

        $totals = ['customer' => 'Total'] + array_intersect_key($data['totals'], array_flip([...array_keys(ReceivablesService::BUCKETS), 'other', 'balance']));
        $columns = [$this->col('customer', 'Customer'), $this->col('phone', 'Phone')];
        foreach (ReceivablesService::BUCKETS as $key => $label) {
            $columns[] = $this->col($key, $label, 'money');
        }
        $columns[] = $this->col('other', 'Other (opening balance, credit)', 'money');
        $columns[] = $this->col('balance', 'Balance', 'money');

        return (new ReportResult('Customer balances (receivables)', 'As at '.now()->format('d M Y, H:i')))
            ->table(null, $columns, $rows, $totals)
            ->note('A positive balance is money the customer owes the shop; a negative one is credit the shop holds for them. Unpaid invoices and delivered service jobs are aged from their date.');
    }

    public function supplierBalances(ReportFilters $f): ReportResult
    {
        $buckets = ReceivablesService::BUCKETS;
        $zero = array_fill_keys(array_keys($buckets), '0.00');
        $bySupplier = [];
        foreach (DB::select("SELECT p.supplier_id, p.due_amount, DATEDIFF(CURDATE(), DATE(p.purchase_date)) AS age
                               FROM purchases p WHERE p.status = 'received' AND p.due_amount > 0".($f->id('supplier_id') ? ' AND p.supplier_id = ?' : ''), $f->id('supplier_id') ? [$f->id('supplier_id')] : []) as $b) {
            $k = ReceivablesService::bucketFor((int) $b->age);
            $bySupplier[$b->supplier_id] ??= $zero;
            $bySupplier[$b->supplier_id][$k] = Decimal::add($bySupplier[$b->supplier_id][$k], (string) $b->due_amount, 2);
        }

        $suppliers = DB::select('SELECT id, name, phone, balance FROM suppliers WHERE (balance <> 0'.($bySupplier ? ' OR id IN ('.implode(',', array_map('intval', array_keys($bySupplier))).')' : '').')'
            .($f->id('supplier_id') ? ' AND id = ?' : '').' ORDER BY name', $f->id('supplier_id') ? [$f->id('supplier_id')] : []);

        $rows = [];
        $totals = $zero + ['supplier' => 'Total', 'other' => '0.00', 'balance' => '0.00'];
        foreach ($suppliers as $s) {
            $b = $bySupplier[$s->id] ?? $zero;
            $due = array_reduce($b, fn ($c, $v) => Decimal::add($c, $v, 2), '0.00');
            $balance = Decimal::round((string) $s->balance, 2);
            $row = ['supplier' => $s->name, 'phone' => $s->phone] + $b + ['other' => Decimal::sub($balance, $due, 2), 'balance' => $balance, '_href' => route('suppliers.show', $s->id)];
            $rows[] = $row;
            foreach ([...array_keys($buckets), 'other', 'balance'] as $k) {
                $totals[$k] = Decimal::add($totals[$k], $row[$k], 2);
            }
        }
        usort($rows, fn ($a, $b) => Decimal::cmp($b['balance'], $a['balance']) ?: strcmp($a['supplier'], $b['supplier']));

        $columns = [$this->col('supplier', 'Supplier'), $this->col('phone', 'Phone')];
        foreach ($buckets as $key => $label) {
            $columns[] = $this->col($key, $label, 'money');
        }
        $columns[] = $this->col('other', 'Other (credit, advances, adjustments)', 'money');
        $columns[] = $this->col('balance', 'We owe', 'money');

        return (new ReportResult('Supplier balances (payables)', 'As at '.now()->format('d M Y, H:i')))
            ->table(null, $columns, $rows, $totals)
            ->note('A positive balance is money the shop owes the supplier; a negative one is credit or an advance the supplier holds for the shop. Unpaid purchases are aged from their date.');
    }
}
