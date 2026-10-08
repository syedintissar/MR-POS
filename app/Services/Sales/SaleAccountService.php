<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Sale;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Keeps a sale's paid / due / payment-status cache equal to the payments that stand, and finds every place where the sales
 * records disagree with each other (used by the twice-daily integrity check; it never repairs anything).
 */
class SaleAccountService
{
    /** Recompute paid, due and status of one sale from its payments. Only payments that are not cancelled count. */
    public function refreshSale(Sale $sale): Sale
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('SaleAccountService must be used inside a database transaction.');
        }

        if ($sale->isVoided()) {
            $sale->forceFill(['paid_amount' => '0.00', 'due_amount' => '0.00', 'payment_status' => 'paid'])->saveQuietly();

            return $sale;
        }

        $paid = Decimal::round((string) (DB::table('sale_payments as sp')
            ->join('customer_payments as cp', 'cp.id', '=', 'sp.customer_payment_id')
            ->where('sp.sale_id', $sale->id)->where('cp.status', 'posted')->where('cp.type', 'receipt')->sum('sp.amount') ?: '0'), 2);

        $due = Decimal::nonNegative(Decimal::sub(Decimal::sub((string) $sale->grand_total, (string) $sale->returned_amount, 2), $paid, 2), 2);

        $sale->forceFill(['paid_amount' => $paid, 'due_amount' => $due, 'payment_status' => Sale::paymentStatusFor($due, $paid)])->saveQuietly();

        return $sale;
    }

    /**
     * @return list<string> human-readable problems
     */
    public function findDrift(): array
    {
        $problems = [];

        // the lines add up to the header
        foreach (DB::select('SELECT s.invoice_no, s.grand_total, s.round_off, s.items_total, s.line_discount_total, s.invoice_discount_amount, s.tax_total,
                                    COALESCE(SUM(i.line_total), 0) AS lines_total, COALESCE(SUM(ROUND(i.quantity * i.unit_price, 2)), 0) AS lines_gross,
                                    COALESCE(SUM(i.discount_amount), 0) AS lines_discount, COALESCE(SUM(i.invoice_discount_share), 0) AS lines_share,
                                    COALESCE(SUM(i.tax_amount), 0) AS lines_tax
                               FROM sales s LEFT JOIN sale_items i ON i.sale_id = s.id
                              GROUP BY s.id, s.invoice_no, s.grand_total, s.round_off, s.items_total, s.line_discount_total, s.invoice_discount_amount, s.tax_total
                             HAVING ABS(s.grand_total - (lines_total + s.round_off)) > 0.004 OR ABS(s.items_total - lines_gross) > 0.004
                                 OR ABS(s.line_discount_total - lines_discount) > 0.004 OR ABS(s.invoice_discount_amount - lines_share) > 0.004
                                 OR ABS(s.tax_total - lines_tax) > 0.004') as $r) {
            $problems[] = "Invoice {$r->invoice_no}: the lines add up to {$r->lines_total} (+ round-off {$r->round_off}) but the invoice total is {$r->grand_total}, or its discount / tax figures do not match its lines.";
        }

        // paid / due / status agree with the payments that stand
        $paid = "COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp JOIN customer_payments cp ON cp.id = sp.customer_payment_id
                            WHERE sp.sale_id = s.id AND cp.status = 'posted' AND cp.type = 'receipt'), 0)";
        foreach (DB::select("SELECT s.invoice_no, s.status, s.grand_total, s.returned_amount, s.paid_amount, s.due_amount, s.payment_status, {$paid} AS real_paid FROM sales s") as $r) {
            if ($r->status === Sale::VOIDED) {
                $ok = bccomp((string) $r->paid_amount, '0', 2) === 0 && bccomp((string) $r->due_amount, '0', 2) === 0;
            } else {
                $due = max(0, (float) $r->grand_total - (float) $r->returned_amount - (float) $r->real_paid);
                $ok = abs((float) $r->paid_amount - (float) $r->real_paid) < 0.004 && abs((float) $r->due_amount - $due) < 0.004
                    && $r->payment_status === Sale::paymentStatusFor(number_format($due, 2, '.', ''), number_format((float) $r->real_paid, 2, '.', ''));
            }
            if (! $ok) {
                $problems[] = "Invoice {$r->invoice_no}: paid / due / status do not match the payments recorded against it.";
            }
        }

        // a payment is never allocated for more than its amount
        foreach (DB::select("SELECT cp.receipt_no, cp.amount, COALESCE(SUM(sp.amount), 0) AS allocated
                               FROM customer_payments cp LEFT JOIN sale_payments sp ON sp.customer_payment_id = cp.id
                              WHERE cp.status = 'posted' GROUP BY cp.id, cp.receipt_no, cp.amount HAVING allocated > cp.amount + 0.004") as $r) {
            $problems[] = "Receipt {$r->receipt_no} is allocated to invoices for more ({$r->allocated}) than its amount ({$r->amount}).";
        }

        // what the invoice says was sold is what left the stock (service items have no stock)
        foreach (DB::select("SELECT s.invoice_no, s.status,
                                    COALESCE(SUM(CASE WHEN p.type = 'stock' THEN i.quantity ELSE 0 END), 0) AS sold,
                                    COALESCE((SELECT -SUM(m.quantity) FROM stock_movements m WHERE m.source_type = 'sale' AND m.source_id = s.id AND m.movement_type = 'sale'), 0) AS taken,
                                    COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.source_type = 'sale' AND m.source_id = s.id AND m.movement_type = 'sale_void'), 0) AS given_back
                               FROM sales s JOIN sale_items i ON i.sale_id = s.id JOIN products p ON p.id = i.product_id
                              GROUP BY s.id, s.invoice_no, s.status
                             HAVING ABS(sold - taken) > 0.0005 OR (s.status = 'voided' AND ABS(taken - given_back) > 0.0005) OR (s.status = 'completed' AND given_back <> 0)") as $r) {
            $problems[] = "Invoice {$r->invoice_no}: {$r->sold} units are on the invoice but the stock ledger shows {$r->taken} taken out and {$r->given_back} given back.";
        }

        // the walk-in customer holds no balance (BR-11)
        foreach (DB::select('SELECT code, balance FROM customers WHERE is_walk_in = 1 AND balance <> 0') as $r) {
            $problems[] = "The walk-in customer {$r->code} has a balance of {$r->balance}; it must always be 0.";
        }

        return $problems;
    }
}
