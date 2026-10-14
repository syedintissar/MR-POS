<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * THE ONLY code allowed to write a supplier's ledger and to change suppliers.balance and a purchase's paid / due / payment status.
 *
 * Sign convention:  balance = SUM(credit) - SUM(debit).
 *   positive balance = the shop owes the supplier;  negative = the supplier holds an advance of ours.
 *
 * Lock order used everywhere (so two terminals can never deadlock):  supplier  ->  purchases (by id)  ->  products (by id)  ->  number sequences.
 */
class SupplierAccountService
{
    public function lockSupplier(int $supplierId): Supplier
    {
        $this->assertInTransaction();

        return Supplier::query()->withTrashed()->whereKey($supplierId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Append one entry to the supplier's account and move the cached balance by the same amount.
     *
     * @param  string  $debit  money we paid or goods we gave back (we owe less)
     * @param  string  $credit  goods we received (we owe more)
     */
    public function postEntry(
        Supplier|int $supplier,
        string $type,
        string $debit,
        string $credit,
        ?Model $source,
        string $description,
        ?User $user = null,
        ?string $date = null,
    ): SupplierLedgerEntry {
        $this->assertInTransaction();

        $debit = Decimal::round($debit, 2);
        $credit = Decimal::round($credit, 2);
        if (Decimal::isPositive($debit) === Decimal::isPositive($credit)) {
            throw new InvalidArgumentException('A ledger entry needs exactly one of debit or credit, and it must be above zero.');
        }

        $s = $this->lockSupplier($supplier instanceof Supplier ? $supplier->getKey() : $supplier);

        $entry = new SupplierLedgerEntry;
        $entry->forceFill([
            'supplier_id' => $s->id,
            'entry_date' => $date ?? now()->toDateString(),
            'entry_type' => $type,
            'debit' => $debit,
            'credit' => $credit,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'created_by' => ($user ?? auth()->user())?->id,
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ])->save();

        $newBalance = Decimal::sub(Decimal::add((string) $s->getRawOriginal('balance'), $credit, 2), $debit, 2);
        $s->forceFill(['balance' => $newBalance])->saveQuietly();

        if ($supplier instanceof Supplier) {
            $supplier->forceFill(['balance' => $newBalance])->syncOriginal();
        }

        return $entry;
    }

    /** What the ledger says the balance is (the truth the cached column must agree with). */
    public function balanceFromLedger(int $supplierId): string
    {
        $row = DB::selectOne('SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS b FROM supplier_ledger_entries WHERE supplier_id = ?', [$supplierId]);

        return Decimal::round((string) $row->b, 2);
    }

    /**
     * Recompute paid / due / payment status of a purchase from the payments allocated to it.
     * Only payments that stand count: a cancelled payment, or an allocation to a cancelled bill, adds nothing.
     */
    public function refreshPurchase(Purchase $purchase): Purchase
    {
        $this->assertInTransaction();

        if ($purchase->status !== Purchase::RECEIVED) {
            $purchase->forceFill(['paid_amount' => '0.00', 'due_amount' => '0.00', 'payment_status' => 'unpaid'])->saveQuietly();

            return $purchase;
        }

        $paid = Decimal::round((string) (DB::table('purchase_payments as pp')
            ->join('supplier_payments as sp', 'sp.id', '=', 'pp.supplier_payment_id')
            ->where('pp.purchase_id', $purchase->id)->where('sp.status', 'posted')->sum('pp.amount') ?: '0'), 2);

        // supplier credit used on this bill pays it too (no money moves: the supplier's balance already said the shop was owed it)
        $credit = Decimal::round((string) (DB::table('supplier_credit_applications')->where('purchase_id', $purchase->id)->where('status', 'posted')->sum('amount') ?: '0'), 2);

        $due = Decimal::nonNegative(Decimal::sub(Decimal::sub(Decimal::sub((string) $purchase->grand_total, (string) $purchase->returned_amount, 2), $paid, 2), $credit, 2), 2);

        $purchase->forceFill([
            'paid_amount' => $paid,
            'due_amount' => $due,
            'payment_status' => Purchase::paymentStatusFor($due, Decimal::add($paid, $credit, 2)),
        ])->saveQuietly();

        return $purchase;
    }

    /**
     * The supplier's account as a statement: an opening figure (everything before $from), the entries in the period
     * with a running balance, and the closing figure. Positive = the shop owes the supplier.
     *
     * @return array{opening: string, rows: list<array{entry: SupplierLedgerEntry, balance: string}>, debit: string, credit: string, closing: string}
     */
    public function statement(int $supplierId, ?string $from = null, ?string $to = null): array
    {
        $opening = '0.00';
        if ($from) {
            $row = DB::selectOne('SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS b FROM supplier_ledger_entries WHERE supplier_id = ? AND entry_date < ?', [$supplierId, $from]);
            $opening = Decimal::round((string) $row->b, 2);
        }

        $entries = SupplierLedgerEntry::query()->where('supplier_id', $supplierId)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderBy('entry_date')->orderBy('id')->get();

        $running = $opening;
        $debit = $credit = '0.00';
        $rows = [];
        foreach ($entries as $e) {
            $running = Decimal::sub(Decimal::add($running, (string) $e->credit, 2), (string) $e->debit, 2);
            $debit = Decimal::add($debit, (string) $e->debit, 2);
            $credit = Decimal::add($credit, (string) $e->credit, 2);
            $rows[] = ['entry' => $e, 'balance' => $running];
        }

        return ['opening' => $opening, 'rows' => $rows, 'debit' => $debit, 'credit' => $credit, 'closing' => $running];
    }

    /**
     * Credit the supplier holds for the shop, from goods sent back: what each posted return left as credit less what was already used,
     * oldest return first. A plain read: callers that change anything lock the supplier (and these returns) first.
     *
     * @return list<array{return_id: int, return_no: string, left: string}>
     */
    public function creditSources(int $supplierId): array
    {
        $out = [];
        foreach (DB::select("SELECT r.id, r.return_no, r.credit_amount - COALESCE((SELECT SUM(a.amount) FROM supplier_credit_applications a WHERE a.purchase_return_id = r.id AND a.status = 'posted'), 0) AS left_amount
                               FROM purchase_returns r WHERE r.supplier_id = ? AND r.status = 'posted' AND r.credit_amount > 0
                              HAVING left_amount > 0 ORDER BY r.return_date, r.id", [$supplierId]) as $r) {
            $out[] = ['return_id' => (int) $r->id, 'return_no' => $r->return_no, 'left' => Decimal::round((string) $r->left_amount, 2)];
        }

        return $out;
    }

    public function creditAvailable(int $supplierId): string
    {
        return array_reduce($this->creditSources($supplierId), fn ($c, $s) => Decimal::add($c, $s['left'], 2), '0.00');
    }

    /** Money we have paid a supplier that is not (or no longer) settling any bill. */
    public function advanceFor(int $supplierId): string
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(sp.amount - COALESCE((
                        SELECT SUM(pp.amount) FROM purchase_payments pp JOIN purchases pu ON pu.id = pp.purchase_id
                         WHERE pp.supplier_payment_id = sp.id AND pu.status <> 'voided'), 0)), 0) AS a
               FROM supplier_payments sp
              WHERE sp.supplier_id = ? AND sp.status = 'posted' AND sp.type = 'payment'",
            [$supplierId],
        );

        return Decimal::round((string) $row->a, 2);
    }

    /**
     * Everything that should agree and does not. Used by the nightly integrity check; never repairs anything.
     *
     * @return list<string>
     */
    public function findDrift(): array
    {
        $problems = [];

        foreach (DB::select('SELECT s.id, s.code, s.name, s.balance, COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0) AS ledger
                               FROM suppliers s LEFT JOIN supplier_ledger_entries l ON l.supplier_id = s.id
                              GROUP BY s.id, s.code, s.name, s.balance HAVING ABS(s.balance - ledger) > 0.004') as $r) {
            $problems[] = "Balance of supplier {$r->code} · {$r->name} is {$r->balance} but its ledger adds up to {$r->ledger}.";
        }

        $paid = "COALESCE((SELECT SUM(pp.amount) FROM purchase_payments pp JOIN supplier_payments sp ON sp.id = pp.supplier_payment_id
                            WHERE pp.purchase_id = p.id AND sp.status = 'posted'), 0)";
        $used = "COALESCE((SELECT SUM(ca.amount) FROM supplier_credit_applications ca WHERE ca.purchase_id = p.id AND ca.status = 'posted'), 0)";
        foreach (DB::select("SELECT p.id, p.purchase_no, p.paid_amount, p.due_amount, p.grand_total, p.returned_amount, p.status, p.payment_status, {$paid} AS real_paid, {$used} AS credit_used
                               FROM purchases p WHERE p.status <> 'draft'") as $r) {
            if ($r->status === 'voided') {
                $ok = bccomp((string) $r->paid_amount, '0', 2) === 0 && bccomp((string) $r->due_amount, '0', 2) === 0;
            } else {
                $due = max(0, (float) $r->grand_total - (float) $r->returned_amount - (float) $r->real_paid - (float) $r->credit_used);
                $ok = abs((float) $r->paid_amount - (float) $r->real_paid) < 0.004 && abs((float) $r->due_amount - $due) < 0.004
                    && $r->payment_status === Purchase::paymentStatusFor(number_format($due, 2, '.', ''), number_format((float) $r->real_paid + (float) $r->credit_used, 2, '.', ''));
            }
            if (! $ok) {
                $problems[] = "Purchase {$r->purchase_no}: paid / due / status do not match the payments recorded against it.";
            }
        }

        // an allocation to a cancelled bill no longer settles anything (that money is an advance again), so it is not counted
        foreach (DB::select("SELECT sp.payment_no, sp.amount, COALESCE(SUM(CASE WHEN pu.status <> 'voided' THEN pp.amount ELSE 0 END), 0) AS allocated
                               FROM supplier_payments sp
                               LEFT JOIN purchase_payments pp ON pp.supplier_payment_id = sp.id
                               LEFT JOIN purchases pu ON pu.id = pp.purchase_id
                              WHERE sp.status = 'posted' GROUP BY sp.id, sp.payment_no, sp.amount HAVING allocated > sp.amount + 0.004") as $r) {
            $problems[] = "Payment {$r->payment_no} is allocated to bills for more ({$r->allocated}) than its amount ({$r->amount}).";
        }

        foreach (DB::select("SELECT p.purchase_no, COALESCE(SUM(pi.quantity), 0) AS bought,
                                    COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.source_type = 'purchase' AND m.source_id = p.id AND m.movement_type = 'purchase'), 0) AS stocked
                               FROM purchases p JOIN purchase_items pi ON pi.purchase_id = p.id
                              WHERE p.status = 'received' GROUP BY p.id, p.purchase_no HAVING ABS(bought - stocked) > 0.0005") as $r) {
            $problems[] = "Purchase {$r->purchase_no} lists {$r->bought} units but only {$r->stocked} went into stock.";
        }

        // supplier credit: a return's uses never add up to more than the credit it left, a use belongs to the supplier of both its return and its bill,
        // and a cancelled bill or return has no use standing
        foreach (DB::select("SELECT r.return_no, r.credit_amount, COALESCE(SUM(a.amount), 0) AS used
                               FROM purchase_returns r JOIN supplier_credit_applications a ON a.purchase_return_id = r.id AND a.status = 'posted'
                              GROUP BY r.id, r.return_no, r.credit_amount HAVING used > r.credit_amount + 0.004") as $r) {
            $problems[] = "Return {$r->return_no} left {$r->credit_amount} of credit but {$r->used} of it was used.";
        }
        foreach (DB::select("SELECT a.id FROM supplier_credit_applications a JOIN purchase_returns r ON r.id = a.purchase_return_id JOIN purchases p ON p.id = a.purchase_id
                              WHERE a.status = 'posted' AND (r.supplier_id <> a.supplier_id OR p.supplier_id <> a.supplier_id OR r.status <> 'posted' OR p.status <> 'received')") as $r) {
            $problems[] = "A use of supplier credit (#{$r->id}) does not match its supplier, its return or its bill.";
        }

        // what each bill says was sent back is what its posted returns add up to, line by line
        foreach (DB::select("SELECT * FROM (
                                SELECT p.purchase_no, p.returned_amount,
                                       COALESCE((SELECT SUM(r.total) FROM purchase_returns r WHERE r.purchase_id = p.id AND r.status = 'posted'), 0) AS real_returned
                                  FROM purchases p) t
                              WHERE ABS(returned_amount - real_returned) > 0.004") as $r) {
            $problems[] = "Purchase {$r->purchase_no}: it says {$r->returned_amount} was sent back but its returns add up to {$r->real_returned}.";
        }
        foreach (DB::select("SELECT * FROM (
                                SELECT p.purchase_no, pi.returned_quantity,
                                       COALESCE((SELECT SUM(ri.quantity) FROM purchase_return_items ri JOIN purchase_returns r ON r.id = ri.purchase_return_id WHERE ri.purchase_item_id = pi.id AND r.status = 'posted'), 0) AS real_returned
                                  FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id) t
                              WHERE ABS(returned_quantity - real_returned) > 0.0005") as $r) {
            $problems[] = "Purchase {$r->purchase_no}: a line shows {$r->returned_quantity} sent back but its returns add up to {$r->real_returned}.";
        }

        // a return's lines add up to its header, the units that left stock are exactly its lines (and are back again when it was cancelled),
        // and the supplier's account carries it once (and reverses it once when cancelled), with the refund the same way
        foreach (DB::select("SELECT * FROM (
                                SELECT r.return_no, r.status, r.subtotal, r.tax_amount, r.total,
                                       COALESCE((SELECT SUM(ri.net_amount) FROM purchase_return_items ri WHERE ri.purchase_return_id = r.id), 0) AS lines_net,
                                       COALESCE((SELECT SUM(ri.tax_amount) FROM purchase_return_items ri WHERE ri.purchase_return_id = r.id), 0) AS lines_tax,
                                       COALESCE((SELECT SUM(ri.quantity) FROM purchase_return_items ri WHERE ri.purchase_return_id = r.id), 0) AS sent,
                                       COALESCE((SELECT SUM(-m.quantity) FROM stock_movements m WHERE m.source_type = 'purchase_return' AND m.source_id = r.id AND m.movement_type = 'purchase_return'), 0) AS left_stock,
                                       COALESCE((SELECT SUM(m.quantity) FROM stock_movements m WHERE m.source_type = 'purchase_return' AND m.source_id = r.id AND m.movement_type = 'purchase_return_void'), 0) AS came_back,
                                       COALESCE((SELECT SUM(l.debit) FROM supplier_ledger_entries l WHERE l.entry_type = 'purchase_return' AND l.source_type = 'purchase_return' AND l.source_id = r.id), 0) AS booked,
                                       COALESCE((SELECT SUM(l.credit) FROM supplier_ledger_entries l WHERE l.entry_type = 'purchase_return_void' AND l.source_type = 'purchase_return' AND l.source_id = r.id), 0) AS reversed
                                  FROM purchase_returns r) t
                              WHERE ABS(subtotal - lines_net) > 0.004 OR ABS(tax_amount - lines_tax) > 0.004 OR ABS(sent - left_stock) > 0.0005
                                 OR ABS(booked - total) > 0.004
                                 OR (status = 'voided' AND (ABS(came_back - sent) > 0.0005 OR ABS(reversed - total) > 0.004))
                                 OR (status = 'posted' AND (came_back <> 0 OR reversed <> 0))") as $r) {
            $problems[] = "Return {$r->return_no}: its lines, the stock that left, or the supplier's account do not match what the return says.";
        }
        foreach (DB::select("SELECT * FROM (
                                SELECT r.return_no, sp.payment_no, sp.amount, sp.status,
                                       COALESCE((SELECT SUM(l.credit) FROM supplier_ledger_entries l WHERE l.entry_type = 'refund_received' AND l.source_type = 'supplier_payment' AND l.source_id = sp.id), 0) AS booked,
                                       COALESCE((SELECT SUM(l.debit) FROM supplier_ledger_entries l WHERE l.entry_type = 'refund_received_void' AND l.source_type = 'supplier_payment' AND l.source_id = sp.id), 0) AS reversed
                                  FROM purchase_returns r JOIN supplier_payments sp ON sp.id = r.supplier_payment_id) t
                              WHERE ABS(booked - amount) > 0.004 OR (status = 'voided' AND ABS(reversed - amount) > 0.004) OR (status = 'posted' AND reversed <> 0)") as $r) {
            $problems[] = "Refund {$r->payment_no} for return {$r->return_no} is not on the supplier's account exactly once.";
        }

        return $problems;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('SupplierAccountService must be used inside a database transaction.');
        }
    }
}
