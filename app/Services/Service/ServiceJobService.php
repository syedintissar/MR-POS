<?php

declare(strict_types=1);

namespace App\Services\Service;

use App\Models\Sale;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Models\ServiceStatusHistory;
use App\Models\User;
use App\Services\Sales\SaleCalculator;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The single writer of a job's cached figures (charges, parts, cost, tax, total, paid, due) and of its status history, and the
 * place that finds every disagreement between the service records (used by the twice-daily integrity check; it never repairs).
 */
class ServiceJobService
{
    public function __construct(private readonly SaleCalculator $calculator) {}

    /** Recompute what the job costs the customer from its lines that stand, then what has been paid against it. */
    public function refresh(ServiceJob $job): ServiceJob
    {
        $this->recalculate($job);

        return $this->refreshPayments($job);
    }

    public function recalculate(ServiceJob $job): ServiceJob
    {
        $this->assertInTransaction();

        $items = ServiceJobItem::query()->where('service_job_id', $job->id)->where('status', ServiceJobItem::ACTIVE)->orderBy('id')->get();
        $t = $this->totals($items, (string) $job->discount_amount, (bool) $job->tax_inclusive);

        $job->forceFill([
            'service_charges' => $t['charges'], 'parts_amount' => $t['parts'], 'parts_cost' => $t['cost'],
            'tax_amount' => $t['tax'], 'grand_total' => $t['grand'],
        ])->saveQuietly();

        return $job;
    }

    /**
     * What the lines come to: charges and parts (after line discounts), what the parts cost the shop, tax and the total.
     *
     * @param  iterable<ServiceJobItem>  $items  lines that stand
     * @return array{charges: string, parts: string, cost: string, discount: string, tax: string, grand: string}
     */
    public function totals(iterable $items, string $jobDiscount, bool $inclusive): array
    {
        $charges = '0.00';
        $parts = '0.00';
        $cost = '0.00';
        $lines = [];
        foreach ($items as $i) {
            if ($i->isPart()) {
                $parts = Decimal::add($parts, (string) $i->line_total, 2);
                $cost = Decimal::add($cost, Decimal::mul((string) $i->quantity, (string) $i->unit_cost, 2), 2);
            } else {
                $charges = Decimal::add($charges, (string) $i->line_total, 2);
            }
            $lines[] = [
                'quantity' => (string) $i->quantity, 'unit_price' => (string) $i->unit_price, 'discount_amount' => (string) $i->discount_amount,
                'tax_rate' => $i->tax_rate === null ? '0' : (string) $i->tax_rate,
            ];
        }

        if ($lines === []) {
            return ['charges' => '0.00', 'parts' => '0.00', 'cost' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'grand' => '0.00'];
        }

        try {
            $calc = $this->calculator->calculate($lines, ['discount_amount' => $jobDiscount, 'tax_inclusive' => $inclusive, 'round_to' => 0]);
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage(), 0, $e);
        }

        return ['charges' => $charges, 'parts' => $parts, 'cost' => $cost, 'discount' => $calc['invoice_discount_amount'], 'tax' => $calc['tax_total'], 'grand' => $calc['grand_total']];
    }

    /** Paid, due and payment status from the receipts that stand. A cancelled job owes nothing and has been paid nothing. */
    public function refreshPayments(ServiceJob $job): ServiceJob
    {
        $this->assertInTransaction();

        if ($job->isCancelled()) {
            $job->forceFill(['paid_amount' => '0.00', 'due_amount' => '0.00', 'payment_status' => 'paid'])->saveQuietly();

            return $job;
        }

        $paid = Decimal::round((string) (DB::table('service_job_payments as jp')
            ->join('customer_payments as cp', 'cp.id', '=', 'jp.customer_payment_id')
            ->where('jp.service_job_id', $job->id)->where('cp.status', 'posted')->where('cp.type', 'receipt')->sum('jp.amount') ?: '0'), 2);

        $due = Decimal::nonNegative(Decimal::sub((string) $job->grand_total, $paid, 2), 2);

        $job->forceFill(['paid_amount' => $paid, 'due_amount' => $due, 'payment_status' => Sale::paymentStatusFor($due, $paid)])->saveQuietly();

        return $job;
    }

    /** A charge line (labour, refill ...): billed, no stock. The caller recomputes the job afterwards. */
    public function addCharge(ServiceJob $job, string $description, string $quantity, string $unitPrice, string $discount, ?string $taxRate, ?User $by): ServiceJobItem
    {
        $this->assertInTransaction();

        $item = new ServiceJobItem;
        $item->forceFill([
            'service_job_id' => $job->id, 'line_type' => ServiceJobItem::CHARGE, 'product_id' => null, 'description' => mb_substr($description, 0, 255),
            'quantity' => $quantity, 'unit_price' => $unitPrice, 'unit_cost' => '0', 'discount_amount' => $discount, 'tax_rate' => $taxRate,
            'line_total' => Decimal::sub(Decimal::mul($quantity, $unitPrice, 2), $discount, 2), 'status' => ServiceJobItem::ACTIVE,
            'added_by' => $by?->id, 'added_at' => now(),
        ])->save();

        return $item;
    }

    /** One line in the job's history (who moved it, from where, to where, why). */
    public function record(ServiceJob $job, ?string $from, string $to, ?User $by, ?string $note = null): ServiceStatusHistory
    {
        $this->assertInTransaction();

        $row = new ServiceStatusHistory;
        $row->forceFill([
            'service_job_id' => $job->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'changed_by' => $by?->id, 'changed_at' => now()->format('Y-m-d H:i:s.u'),
        ])->save();

        return $row;
    }

    /**
     * Everything that should agree and does not. Never repairs anything.
     *
     * @return list<string>
     */
    public function findDrift(): array
    {
        $problems = [];

        $jobs = ServiceJob::query()->orderBy('id')->get();
        $items = ServiceJobItem::query()->where('status', ServiceJobItem::ACTIVE)->get()->groupBy('service_job_id');

        // the job's figures are what its lines come to
        foreach ($jobs as $job) {
            if ($job->isCancelled()) {
                continue;
            }
            try {
                $t = $this->totals($items->get($job->id, collect()), (string) $job->discount_amount, (bool) $job->tax_inclusive);
            } catch (DomainException) {
                $problems[] = "Service job {$job->job_no}: its lines can no longer be added up (a discount is larger than the job).";

                continue;
            }
            if (Decimal::cmp($t['charges'], (string) $job->service_charges) !== 0 || Decimal::cmp($t['parts'], (string) $job->parts_amount) !== 0
                || Decimal::cmp($t['cost'], (string) $job->parts_cost) !== 0 || Decimal::cmp($t['tax'], (string) $job->tax_amount) !== 0
                || Decimal::cmp($t['grand'], (string) $job->grand_total) !== 0) {
                $problems[] = "Service job {$job->job_no}: its lines add up to {$t['grand']} (charges {$t['charges']}, parts {$t['parts']}, tax {$t['tax']}) but the job says {$job->grand_total} (charges {$job->service_charges}, parts {$job->parts_amount}, tax {$job->tax_amount}).";
            }
        }

        // paid / due / status agree with the receipts that stand
        foreach (DB::select("SELECT j.job_no, j.status, j.grand_total, j.paid_amount, j.due_amount, j.payment_status,
                                    COALESCE((SELECT SUM(jp.amount) FROM service_job_payments jp JOIN customer_payments cp ON cp.id = jp.customer_payment_id
                                               WHERE jp.service_job_id = j.id AND cp.status = 'posted' AND cp.type = 'receipt'), 0) AS real_paid
                               FROM service_jobs j") as $r) {
            if ($r->status === ServiceJob::CANCELLED) {
                $ok = Decimal::isZero((string) $r->paid_amount) && Decimal::isZero((string) $r->due_amount);
            } else {
                $due = Decimal::nonNegative(Decimal::sub((string) $r->grand_total, (string) $r->real_paid, 2), 2);
                $ok = Decimal::cmp((string) $r->paid_amount, (string) $r->real_paid) === 0 && Decimal::cmp((string) $r->due_amount, $due) === 0
                    && $r->payment_status === Sale::paymentStatusFor($due, Decimal::round((string) $r->real_paid, 2));
            }
            if (! $ok) {
                $problems[] = "Service job {$r->job_no}: paid / due / status do not match the payments recorded against it.";
            }
        }

        // the status the job shows is the last step of its history
        foreach (DB::select('SELECT j.job_no, j.status, (SELECT h.to_status FROM service_status_history h WHERE h.service_job_id = j.id ORDER BY h.id DESC LIMIT 1) AS last_status FROM service_jobs j') as $r) {
            if ($r->last_status !== $r->status) {
                $problems[] = "Service job {$r->job_no} shows \"{$r->status}\" but its history ends at \"".($r->last_status ?? 'nothing').'".';
            }
        }

        // parts: what left the stock for a job is exactly what its lines say (taken back parts net to zero; written-off ones stay out)
        foreach (DB::select("SELECT * FROM (
                                SELECT j.job_no, p.name,
                                       COALESCE((SELECT SUM(-m.quantity) FROM stock_movements m WHERE m.source_type = 'service_job' AND m.source_id = j.id AND m.product_id = p.id
                                                  AND m.movement_type IN ('service_consumption','service_return')), 0) AS out_of_stock,
                                       COALESCE((SELECT SUM(i.quantity) FROM service_job_items i WHERE i.service_job_id = j.id AND i.product_id = p.id AND i.line_type = 'part'
                                                  AND (i.status = 'active' OR i.removal_disposition = 'written_off')), 0) AS on_lines
                                  FROM service_jobs j JOIN products p ON p.id IN (SELECT DISTINCT i2.product_id FROM service_job_items i2 WHERE i2.service_job_id = j.id AND i2.line_type = 'part')) t
                              WHERE ABS(out_of_stock - on_lines) > 0.0004") as $r) {
            $problems[] = "Service job {$r->job_no}: {$r->name} left the stock as {$r->out_of_stock} but the job's lines say {$r->on_lines}.";
        }

        // delivered jobs of named customers are on the account once; nothing else is
        foreach (DB::select("SELECT * FROM (
                                SELECT j.job_no, j.status, j.grand_total,
                                       COALESCE((SELECT SUM(l.debit) FROM customer_ledger_entries l WHERE l.entry_type = 'service_invoice' AND l.source_type = 'service_job' AND l.source_id = j.id), 0) AS booked
                                  FROM service_jobs j JOIN customers c ON c.id = j.customer_id WHERE c.is_walk_in = 0) t
                              WHERE (status = 'delivered' AND ABS(booked - grand_total) > 0.004) OR (status <> 'delivered' AND booked <> 0)") as $r) {
            $problems[] = "Service job {$r->job_no} ({$r->status}, {$r->grand_total}) is on the customer's account as {$r->booked}.";
        }

        return $problems;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('ServiceJobService must be used inside a database transaction.');
        }
    }
}
