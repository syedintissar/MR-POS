<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Who owes the shop, and for how long (SRS CUS-07). Outstanding invoices of named customers, aged by invoice date:
 * current (today), 1-30, 31-60, 61-90 and over 90 days. What a customer owes that is not on an invoice (an opening balance,
 * a correction, or money they hold in credit) is shown as "other" so every row adds up to the customer's balance.
 */
class ReceivablesService
{
    public const BUCKETS = ['current' => 'Today', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90' => 'Over 90 days'];

    public static function bucketFor(int $ageDays): string
    {
        return match (true) {
            $ageDays <= 0 => 'current',
            $ageDays <= 30 => 'd1_30',
            $ageDays <= 60 => 'd31_60',
            $ageDays <= 90 => 'd61_90',
            default => 'd90',
        };
    }

    /**
     * @return array{rows: list<array{customer: Customer, buckets: array<string, string>, due: string, other: string, balance: string, invoices: list<array<string, mixed>>}>, totals: array<string, string>}
     */
    public function report(?int $customerId = null): array
    {
        // unpaid invoices and delivered service jobs (a job is invoiced when it is delivered)
        $only = $customerId ? ' AND %s.customer_id = ?' : '';
        $invoices = DB::select(
            "SELECT 'sale' AS kind, s.id, s.invoice_no, s.customer_id, s.sale_date AS doc_date, s.grand_total, s.paid_amount, s.due_amount, DATEDIFF(CURDATE(), DATE(s.sale_date)) AS age
               FROM sales s JOIN customers c ON c.id = s.customer_id
              WHERE s.status = 'completed' AND s.due_amount > 0 AND c.is_walk_in = 0".sprintf($only, 's')."
             UNION ALL
             SELECT 'job' AS kind, j.id, CONCAT(j.invoice_no, ' (', j.job_no, ')') AS invoice_no, j.customer_id, j.invoiced_at AS doc_date, j.grand_total, j.paid_amount, j.due_amount, DATEDIFF(CURDATE(), DATE(j.invoiced_at)) AS age
               FROM service_jobs j JOIN customers c ON c.id = j.customer_id
              WHERE j.status = 'delivered' AND j.due_amount > 0 AND c.is_walk_in = 0".sprintf($only, 'j').'
              ORDER BY doc_date, kind, id',
            $customerId ? [$customerId, $customerId] : [],
        );

        $zero = array_fill_keys(array_keys(self::BUCKETS), '0.00');
        $byCustomer = [];
        foreach ($invoices as $i) {
            $b = self::bucketFor((int) $i->age);
            $byCustomer[$i->customer_id]['buckets'] ??= $zero;
            $byCustomer[$i->customer_id]['buckets'][$b] = Decimal::add($byCustomer[$i->customer_id]['buckets'][$b], (string) $i->due_amount, 2);
            $byCustomer[$i->customer_id]['invoices'][] = [
                'id' => (int) $i->id, 'kind' => $i->kind, 'url' => $i->kind === 'job' ? route('service.jobs.show', (int) $i->id) : route('sales.show', (int) $i->id),
                'invoice_no' => $i->invoice_no, 'date' => (string) $i->doc_date, 'age' => (int) $i->age, 'bucket' => $b,
                'total' => Decimal::round((string) $i->grand_total, 2), 'paid' => Decimal::round((string) $i->paid_amount, 2), 'due' => Decimal::round((string) $i->due_amount, 2),
            ];
        }

        // customers who owe (or hold) something that is not on an invoice still belong in the report
        $others = Customer::query()->where('is_walk_in', false)->where('balance', '!=', 0)->when($customerId, fn ($q) => $q->where('id', $customerId))->pluck('id')->all();
        $ids = array_values(array_unique([...array_keys($byCustomer), ...$others]));
        $customers = Customer::query()->withTrashed()->whereIn('id', $ids)->orderBy('name')->get();

        $rows = [];
        $totals = $zero + ['due' => '0.00', 'other' => '0.00', 'balance' => '0.00'];
        foreach ($customers as $c) {
            $buckets = $byCustomer[$c->id]['buckets'] ?? $zero;
            $due = array_reduce($buckets, fn ($carry, $v) => Decimal::add($carry, $v, 2), '0.00');
            $balance = (string) $c->getRawOriginal('balance');
            $other = Decimal::sub($balance, $due, 2);

            $rows[] = ['customer' => $c, 'buckets' => $buckets, 'due' => $due, 'other' => $other, 'balance' => $balance, 'invoices' => $byCustomer[$c->id]['invoices'] ?? []];
            foreach ($buckets as $k => $v) {
                $totals[$k] = Decimal::add($totals[$k], $v, 2);
            }
            $totals['due'] = Decimal::add($totals['due'], $due, 2);
            $totals['other'] = Decimal::add($totals['other'], $other, 2);
            $totals['balance'] = Decimal::add($totals['balance'], $balance, 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
