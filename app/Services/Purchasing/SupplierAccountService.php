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

        $due = Decimal::nonNegative(Decimal::sub(Decimal::sub((string) $purchase->grand_total, (string) $purchase->returned_amount, 2), $paid, 2), 2);

        $purchase->forceFill([
            'paid_amount' => $paid,
            'due_amount' => $due,
            'payment_status' => Purchase::paymentStatusFor($due, $paid),
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
        foreach (DB::select("SELECT p.id, p.purchase_no, p.paid_amount, p.due_amount, p.grand_total, p.returned_amount, p.status, p.payment_status, {$paid} AS real_paid
                               FROM purchases p WHERE p.status <> 'draft'") as $r) {
            if ($r->status === 'voided') {
                $ok = bccomp((string) $r->paid_amount, '0', 2) === 0 && bccomp((string) $r->due_amount, '0', 2) === 0;
            } else {
                $due = max(0, (float) $r->grand_total - (float) $r->returned_amount - (float) $r->real_paid);
                $ok = abs((float) $r->paid_amount - (float) $r->real_paid) < 0.004 && abs((float) $r->due_amount - $due) < 0.004
                    && $r->payment_status === Purchase::paymentStatusFor(number_format($due, 2, '.', ''), number_format((float) $r->real_paid, 2, '.', ''));
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

        return $problems;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('SupplierAccountService must be used inside a database transaction.');
        }
    }
}
