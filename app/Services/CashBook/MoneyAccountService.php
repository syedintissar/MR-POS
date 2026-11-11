<?php

declare(strict_types=1);

namespace App\Services\CashBook;

use App\Models\MoneyAccount;
use App\Support\Decimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The cash & bank book: what each account holds and how it got there.
 *
 * No amount is stored twice. Money that came in or went out with a document that already exists (a customer receipt or refund, a supplier
 * payment or refund, an expense) is read from that document, which remembers the account it landed in; money that moved on its own (a bank
 * deposit, owner money, an opening balance, a count difference) is a money_transactions row. A cancelled document counts nowhere, as everywhere else.
 * Because the balance is added up, not kept, it cannot drift: the same rows feed "Money in and out by payment method".
 */
class MoneyAccountService
{
    public const MAX_ROWS = 1000;

    public const UNCLOSED_CACHE = 'cashbook.unclosed';

    /**
     * Every movement of money as one row: account_id, d (date), t (when it was entered), source, source_id, doc_no, what, party, note, money_in, money_out.
     * Customer credit never appears: that method has no account because store credit is not money.
     */
    public function movementsSql(): string
    {
        // each receipt, payment and expense carries the account it landed in (money_account_id), written by a database trigger when the row is made:
        // the account of its payment method, or the drawer of the person who entered it for cash that would go to the main drawer
        return "
            SELECT cp.money_account_id AS account_id, DATE(cp.payment_date) AS d, cp.payment_date AS t, 'customer_payment' AS source, cp.id AS source_id, cp.receipt_no AS doc_no,
                   CASE WHEN cp.type = 'refund' THEN 'Refund to customer' ELSE 'Received from customer' END AS what, c.name AS party, cp.notes AS note,
                   CASE WHEN cp.type = 'receipt' THEN cp.amount ELSE 0 END AS money_in, CASE WHEN cp.type = 'refund' THEN cp.amount ELSE 0 END AS money_out
              FROM customer_payments cp JOIN customers c ON c.id = cp.customer_id
             WHERE cp.status = 'posted' AND cp.money_account_id IS NOT NULL
            UNION ALL
            SELECT sp.money_account_id, sp.payment_date, sp.created_at, 'supplier_payment', sp.id, sp.payment_no,
                   CASE WHEN sp.type = 'refund' THEN 'Refund from supplier' ELSE 'Paid to supplier' END, s.name, sp.notes,
                   CASE WHEN sp.type = 'refund' THEN sp.amount ELSE 0 END, CASE WHEN sp.type = 'payment' THEN sp.amount ELSE 0 END
              FROM supplier_payments sp JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.status = 'posted' AND sp.money_account_id IS NOT NULL
            UNION ALL
            SELECT e.money_account_id, e.expense_date, e.created_at, 'expense', e.id, e.expense_no, CONCAT('Expense: ', ec.name), e.payee, e.description, 0, e.amount
              FROM expenses e JOIN expense_categories ec ON ec.id = e.expense_category_id
             WHERE e.status = 'posted' AND e.money_account_id IS NOT NULL
            UNION ALL
            SELECT mt.to_account_id, mt.txn_date, mt.created_at, 'money_transaction', mt.id, mt.txn_no,
                   CASE mt.type WHEN 'transfer' THEN 'Transfer in' WHEN 'owner_in' THEN 'Owner put money in' WHEN 'opening_balance' THEN 'Opening balance' ELSE 'Cash over (count)' END,
                   fa.name, mt.description, mt.amount, 0
              FROM money_transactions mt LEFT JOIN money_accounts fa ON fa.id = mt.from_account_id
             WHERE mt.status = 'posted' AND mt.to_account_id IS NOT NULL
            UNION ALL
            SELECT mt.from_account_id, mt.txn_date, mt.created_at, 'money_transaction', mt.id, mt.txn_no,
                   CASE mt.type WHEN 'transfer' THEN 'Transfer out' WHEN 'owner_out' THEN 'Owner took money out' ELSE 'Cash short (count)' END,
                   ta.name, mt.description, 0, mt.amount
              FROM money_transactions mt LEFT JOIN money_accounts ta ON ta.id = mt.to_account_id
             WHERE mt.status = 'posted' AND mt.from_account_id IS NOT NULL";
    }

    /**
     * What every account holds, at the end of a day (or now when no date is given).
     *
     * @return array<int, string> account id => balance, every account present
     */
    public function balances(?string $asAt = null): array
    {
        $out = [];
        foreach (DB::table('money_accounts')->pluck('id') as $id) {
            $out[(int) $id] = '0.00';
        }
        $where = $asAt !== null ? 'WHERE m.d <= ?' : '';
        foreach (DB::select("SELECT m.account_id, SUM(m.money_in) - SUM(m.money_out) AS balance FROM ({$this->movementsSql()}) m {$where} GROUP BY m.account_id", $asAt !== null ? [$asAt] : []) as $r) {
            $out[(int) $r->account_id] = Decimal::round((string) $r->balance, 2);
        }

        return $out;
    }

    /** What one account holds now (every posted movement, whatever its date). */
    public function balance(int $accountId): string
    {
        $r = DB::selectOne("SELECT COALESCE(SUM(m.money_in) - SUM(m.money_out), 0) AS balance FROM ({$this->movementsSql()}) m WHERE m.account_id = ?", [$accountId]);

        return Decimal::round((string) $r->balance, 2);
    }

    /**
     * The book of one account for a period: what it held before, every movement with a running balance, and what it holds after.
     *
     * @return array{opening: string, money_in: string, money_out: string, closing: string, rows: list<array<string, mixed>>, truncated: bool}
     */
    public function book(MoneyAccount $account, string $from, string $to): array
    {
        $sql = $this->movementsSql();
        $opening = Decimal::round((string) DB::selectOne("SELECT COALESCE(SUM(m.money_in) - SUM(m.money_out), 0) AS b FROM ({$sql}) m WHERE m.account_id = ? AND m.d < ?", [$account->id, $from])->b, 2);
        $sum = DB::selectOne("SELECT COALESCE(SUM(m.money_in), 0) AS i, COALESCE(SUM(m.money_out), 0) AS o FROM ({$sql}) m WHERE m.account_id = ? AND m.d >= ? AND m.d <= ?", [$account->id, $from, $to]);
        $in = Decimal::round((string) $sum->i, 2);
        $out = Decimal::round((string) $sum->o, 2);

        $found = DB::select("SELECT m.* FROM ({$sql}) m WHERE m.account_id = ? AND m.d >= ? AND m.d <= ? ORDER BY m.d, m.t, m.source, m.source_id LIMIT ".(self::MAX_ROWS + 1), [$account->id, $from, $to]);
        $truncated = count($found) > self::MAX_ROWS;

        $rows = [];
        $running = $opening;
        foreach (array_slice($found, 0, self::MAX_ROWS) as $r) {
            $running = Decimal::sub(Decimal::add($running, (string) $r->money_in, 2), (string) $r->money_out, 2);
            $rows[] = [
                'date' => (string) $r->d, 'source' => (string) $r->source, 'source_id' => (int) $r->source_id, 'doc_no' => (string) $r->doc_no, 'what' => (string) $r->what,
                'party' => $r->party, 'note' => $r->note,
                'money_in' => Decimal::isZero((string) $r->money_in) ? null : Decimal::round((string) $r->money_in, 2),
                'money_out' => Decimal::isZero((string) $r->money_out) ? null : Decimal::round((string) $r->money_out, 2),
                'balance' => $running,
            ];
        }

        return ['opening' => $opening, 'money_in' => $in, 'money_out' => $out, 'closing' => Decimal::sub(Decimal::add($opening, $in, 2), $out, 2), 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Payment methods that take real money but pay into no account: their money is in no balance until they are linked.
     *
     * @return list<array{id: int, name: string, used: bool}>
     */
    public function unlinkedMethods(): array
    {
        $out = [];
        foreach (DB::select("SELECT pm.id, pm.name, pm.is_active,
                    (EXISTS (SELECT 1 FROM customer_payments x WHERE x.payment_method_id = pm.id AND x.status = 'posted' AND x.money_account_id IS NULL)
                  OR EXISTS (SELECT 1 FROM supplier_payments x WHERE x.payment_method_id = pm.id AND x.status = 'posted' AND x.money_account_id IS NULL)
                  OR EXISTS (SELECT 1 FROM expenses x WHERE x.payment_method_id = pm.id AND x.status = 'posted' AND x.money_account_id IS NULL)) AS used
               FROM payment_methods pm WHERE pm.kind <> 'customer_credit' AND pm.money_account_id IS NULL ORDER BY pm.sort_order, pm.name") as $r) {
            if ($r->is_active || $r->used) {
                $out[] = ['id' => (int) $r->id, 'name' => (string) $r->name, 'used' => (bool) $r->used];
            }
        }

        return $out;
    }

    /**
     * A payment method was given an account: the money it took while it had none is put into that account too.
     * Rows that already landed somewhere are never moved, so this changes the future and fills the gap, never the past.
     */
    public function adoptUnlinked(int $methodId, int $accountId): int
    {
        $moved = 0;
        foreach (['customer_payments', 'supplier_payments', 'expenses'] as $table) {
            $moved += DB::table($table)->where('payment_method_id', $methodId)->whereNull('money_account_id')->update(['money_account_id' => $accountId]);
        }

        return $moved;
    }

    /**
     * Cash drawers that took or paid money on an earlier day and have not been counted since: the reminder on the dashboard and the till.
     * Remembered for a minute so the till stays fast; a closing or its cancellation forgets it at once.
     *
     * @return list<array{id: int, name: string, since: string}>
     */
    public function unclosedDrawers(): array
    {
        return Cache::remember(self::UNCLOSED_CACHE, 60, function () {
            $out = [];
            $today = now()->toDateString();
            foreach (DB::select("SELECT a.id, a.name, (SELECT MAX(dc.closed_at) FROM day_closings dc WHERE dc.money_account_id = a.id AND dc.status = 'posted') AS last_closed
                                   FROM money_accounts a WHERE a.kind = 'cash' AND a.is_active = 1 ORDER BY a.sort_order, a.name") as $a) {
                $r = DB::selectOne("SELECT MIN(m.d) AS since FROM ({$this->movementsSql()}) m WHERE m.account_id = ? AND m.d < ? AND (? IS NULL OR m.t > ?)", [$a->id, $today, $a->last_closed, $a->last_closed]);
                if ($r->since !== null) {
                    $out[] = ['id' => (int) $a->id, 'name' => (string) $a->name, 'since' => (string) $r->since];
                }
            }

            return $out;
        });
    }

    public function forgetReminder(): void
    {
        Cache::forget(self::UNCLOSED_CACHE);
    }

    /**
     * What must always be true of the cash & bank book (for pos:integrity:check). Balances themselves cannot drift (they are added up),
     * so this checks the documents: every closing agrees with its own figures and with the entry that carries its difference.
     *
     * @return list<string>
     */
    public function findDrift(): array
    {
        $problems = [];

        foreach (DB::select("SELECT name FROM payment_methods WHERE kind = 'customer_credit' AND money_account_id IS NOT NULL") as $r) {
            $problems[] = "Payment method \"{$r->name}\" is customer credit but pays into a money account: store credit is not money.";
        }

        foreach (DB::select("SELECT a.name, COUNT(*) AS n FROM money_transactions mt JOIN money_accounts a ON a.id = mt.to_account_id
                              WHERE mt.type = 'opening_balance' AND mt.status = 'posted' GROUP BY a.id, a.name HAVING COUNT(*) > 1") as $r) {
            $problems[] = "Account \"{$r->name}\" has {$r->n} opening balances; there can be only one.";
        }

        foreach (DB::select('SELECT dc.closing_no, dc.status, dc.difference, dc.money_account_id, mt.txn_no, mt.type, mt.amount, mt.status AS txn_status, mt.from_account_id, mt.to_account_id
                               FROM day_closings dc LEFT JOIN money_transactions mt ON mt.id = dc.adjustment_transaction_id') as $r) {
            $difference = Decimal::round((string) $r->difference, 2);
            if (Decimal::isZero($difference)) {
                continue;   // the database constraint already guarantees there is no entry
            }
            $over = Decimal::isPositive($difference);
            $expectedType = $over ? 'count_over' : 'count_short';
            $account = $over ? $r->to_account_id : $r->from_account_id;
            if ($r->txn_no === null || $r->type !== $expectedType || Decimal::cmp((string) $r->amount, Decimal::abs($difference, 2)) !== 0 || (int) $account !== (int) $r->money_account_id) {
                $problems[] = "Day closing {$r->closing_no} found a difference of {$difference}, but its entry ".($r->txn_no ?? '(missing)').' does not carry exactly that for the same account.';
            } elseif ($r->status !== $r->txn_status) {
                $problems[] = "Day closing {$r->closing_no} is {$r->status} but its entry {$r->txn_no} is {$r->txn_status}.";
            }
        }

        foreach (DB::select("SELECT mt.txn_no FROM money_transactions mt WHERE mt.type IN ('count_over','count_short')
                                AND (SELECT COUNT(*) FROM day_closings dc WHERE dc.adjustment_transaction_id = mt.id) <> 1") as $r) {
            $problems[] = "Entry {$r->txn_no} is a count difference but does not belong to exactly one day closing.";
        }

        return $problems;
    }
}
