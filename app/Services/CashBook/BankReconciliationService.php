<?php

declare(strict_types=1);

namespace App\Services\CashBook;

use App\Models\BankReconciliation;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Matching a bank or wallet account against its statement.
 *
 * The person enters the last day and the closing balance of the statement, then ticks every line of the book that is on it.
 * What the statement started with, plus the ticked money in, less the ticked money out, must equal its closing balance: then the
 * statement is completed. Lines not on the statement yet (a cheque not cleared, a deposit on its way) stay open for the next one.
 * A line can be matched once only (a unique key), and a completed statement is not reopened by a back-dated cash & bank entry.
 */
class BankReconciliationService
{
    public function __construct(
        private readonly MoneyAccountService $accounts,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function start(MoneyAccount $account, string $statementDate, string $statementBalance, ?string $notes, User $by): BankReconciliation
    {
        $this->allow($by, 'cashbook.reconcile');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $statementDate) || strtotime($statementDate) === false) {
            throw new DomainException('Enter the last day the statement covers.');
        }
        $date = date('Y-m-d', strtotime($statementDate));
        if ($date > now()->toDateString()) {
            throw new DomainException('The statement date cannot be in the future.');
        }
        if (! Decimal::isNumber($statementBalance)) {
            throw new DomainException('Enter the closing balance printed on the statement.');
        }

        return DB::transaction(function () use ($account, $date, $statementBalance, $notes, $by) {
            $locked = MoneyAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isCash()) {
                throw new DomainException("\"{$locked->name}\" is cash: it is counted (Close the day), not matched against a statement.");
            }
            if (BankReconciliation::query()->where('money_account_id', $locked->id)->where('status', BankReconciliation::OPEN)->exists()) {
                throw new DomainException("A statement of \"{$locked->name}\" is already being matched. Finish or cancel that one first.");
            }
            $last = BankReconciliation::query()->where('money_account_id', $locked->id)->where('status', BankReconciliation::COMPLETED)->orderByDesc('statement_date')->orderByDesc('id')->first();
            if ($last && $date <= $last->statement_date->toDateString()) {
                throw new DomainException("\"{$locked->name}\" has already been matched up to {$last->statement_date->format('d M Y')}. Choose a later statement date.");
            }

            $r = new BankReconciliation;
            $r->forceFill([
                'reconciliation_no' => $this->numbers->next('bank_reconciliation'), 'money_account_id' => $locked->id, 'statement_date' => $date,
                'opening_balance' => $last ? (string) $last->statement_balance : '0.00', 'statement_balance' => Decimal::round($statementBalance, 2),
                'notes' => ($notes = trim((string) $notes)) !== '' ? mb_substr($notes, 0, 255) : null, 'status' => BankReconciliation::OPEN, 'created_by' => $by->id,
            ])->save();
            $this->audit->log('bank_reconciliation_started', 'cashbook', "{$r->reconciliation_no}: matching {$locked->name} up to {$date}, statement balance {$r->statement_balance}", $r, user: $by);

            return $r->refresh();
        }, 3);
    }

    /**
     * The lines the person can tick: everything in the book of the account up to the statement date that no other statement has matched.
     *
     * @return list<array{key: string, date: string, doc_no: string, what: string, party: ?string, money_in: string, money_out: string, ticked: bool}>
     */
    public function lines(BankReconciliation $r): array
    {
        $rows = DB::select("SELECT m.*, i.bank_reconciliation_id AS matched_by FROM ({$this->accounts->movementsSql()}) m
                              LEFT JOIN bank_reconciliation_items i ON i.money_account_id = m.account_id AND i.source = m.source AND i.source_id = m.source_id
                             WHERE m.account_id = ? AND m.d <= ? AND (i.id IS NULL OR i.bank_reconciliation_id = ?)
                             ORDER BY m.d, m.t, m.source, m.source_id", [$r->money_account_id, $r->statement_date->toDateString(), $r->id]);

        return array_map(fn ($m) => [
            'key' => $m->source.':'.$m->source_id, 'date' => (string) $m->d, 'doc_no' => (string) $m->doc_no, 'what' => (string) $m->what, 'party' => $m->party,
            'money_in' => Decimal::round((string) $m->money_in, 2), 'money_out' => Decimal::round((string) $m->money_out, 2), 'ticked' => $m->matched_by !== null,
        ], $rows);
    }

    /** @return array{opening: string, money_in: string, money_out: string, matched: string, statement: string, difference: string, count: int} */
    public function totals(BankReconciliation $r): array
    {
        $s = DB::selectOne('SELECT COALESCE(SUM(money_in), 0) AS i, COALESCE(SUM(money_out), 0) AS o, COUNT(*) AS n FROM bank_reconciliation_items WHERE bank_reconciliation_id = ?', [$r->id]);
        $in = Decimal::round((string) $s->i, 2);
        $out = Decimal::round((string) $s->o, 2);
        $matched = Decimal::sub(Decimal::add((string) $r->opening_balance, $in, 2), $out, 2);

        return [
            'opening' => (string) $r->opening_balance, 'money_in' => $in, 'money_out' => $out, 'matched' => $matched, 'statement' => (string) $r->statement_balance,
            'difference' => Decimal::sub((string) $r->statement_balance, $matched, 2), 'count' => (int) $s->n,
        ];
    }

    /**
     * Keeps the ticks of a statement that is still open (the whole set is replaced), and completes it when asked and the figures agree.
     *
     * @param  list<string>  $keys  "source:id" of every ticked line
     */
    public function save(BankReconciliation $r, array $keys, bool $complete, User $by): BankReconciliation
    {
        $this->allow($by, 'cashbook.reconcile');

        return DB::transaction(function () use ($r, $keys, $complete, $by) {
            $locked = BankReconciliation::query()->whereKey($r->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new DomainException("{$locked->reconciliation_no} is {$locked->status}: its ticks can no longer be changed.");
            }

            $wanted = array_fill_keys(array_map('strval', $keys), true);
            DB::table('bank_reconciliation_items')->where('bank_reconciliation_id', $locked->id)->delete();
            $rows = [];
            foreach ($this->lines($locked) as $line) {
                if (isset($wanted[$line['key']])) {
                    [$source, $id] = explode(':', $line['key'], 2);
                    $rows[] = [
                        'bank_reconciliation_id' => $locked->id, 'money_account_id' => $locked->money_account_id, 'source' => $source, 'source_id' => (int) $id,
                        'doc_no' => $line['doc_no'], 'line_date' => $line['date'], 'money_in' => $line['money_in'], 'money_out' => $line['money_out'],
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('bank_reconciliation_items')->insert($chunk);
            }

            if ($complete) {
                $t = $this->totals($locked);
                if (! Decimal::isZero($t['difference'])) {
                    throw new DomainException('The ticked lines add up to '.$t['matched'].' but the statement says '.$t['statement'].' (a difference of '.$t['difference'].'). Find the line that is missing or wrongly ticked; a bank charge not entered yet is recorded as an expense first.');
                }
                $locked->forceFill(['status' => BankReconciliation::COMPLETED, 'completed_at' => now(), 'completed_by' => $by->id])->save();
                $this->audit->log('bank_reconciliation_completed', 'cashbook', "{$locked->reconciliation_no}: {$t['count']} line(s) matched, statement balance {$t['statement']}", $locked, new: $t, user: $by);
            }

            return $locked->refresh();
        }, 3);
    }

    /** Cancels a statement matching: its lines are open again. A completed one only when it is the latest of its account. */
    public function void(BankReconciliation $r, string $reason, User $by): BankReconciliation
    {
        $this->allow($by, 'cashbook.void');
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the statement matching.');
        }

        return DB::transaction(function () use ($r, $reason, $by) {
            MoneyAccount::query()->whereKey($r->money_account_id)->lockForUpdate()->firstOrFail();
            $locked = BankReconciliation::query()->whereKey($r->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->reconciliation_no} has already been cancelled.");
            }
            if (BankReconciliation::query()->where('money_account_id', $locked->money_account_id)->where('status', '<>', BankReconciliation::VOIDED)->where('id', '>', $locked->id)->exists()) {
                throw new DomainException("Only the latest statement of an account can be cancelled. {$locked->reconciliation_no} has a later one after it.");
            }

            $lines = DB::table('bank_reconciliation_items')->where('bank_reconciliation_id', $locked->id)->delete();
            $locked->forceFill([
                'status' => BankReconciliation::VOIDED, 'completed_at' => null, 'completed_by' => null,
                'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255),
            ])->save();
            $this->audit->log('bank_reconciliation_voided', 'cashbook', "{$locked->reconciliation_no} cancelled ({$lines} matched line(s) opened again): {$reason}", $locked, new: ['reason' => $reason, 'lines' => $lines], user: $by);

            return $locked->refresh();
        }, 3);
    }

    /**
     * For pos:integrity:check: a line matched on a completed statement must still be in the book with the same amounts
     * (a document cancelled or moved after its statement was closed is reported, never silently accepted).
     *
     * @return list<string>
     */
    public function findDrift(): array
    {
        $problems = [];
        foreach (DB::select("SELECT r.reconciliation_no, i.doc_no, i.money_in, i.money_out, m.money_in AS now_in, m.money_out AS now_out, m.source AS found
                               FROM bank_reconciliation_items i JOIN bank_reconciliations r ON r.id = i.bank_reconciliation_id
                               LEFT JOIN ({$this->accounts->movementsSql()}) m ON m.account_id = i.money_account_id AND m.source = i.source AND m.source_id = i.source_id
                              WHERE r.status = 'completed' AND (m.source IS NULL OR m.money_in <> i.money_in OR m.money_out <> i.money_out)") as $x) {
            $problems[] = $x->found === null
                ? "{$x->doc_no} was matched on statement {$x->reconciliation_no} but is no longer in the book of that account (cancelled or moved afterwards)."
                : "{$x->doc_no} was matched on statement {$x->reconciliation_no} for ".Decimal::round((string) $x->money_in, 2).' in / '.Decimal::round((string) $x->money_out, 2).' out, but the book now says '.Decimal::round((string) $x->now_in, 2).' / '.Decimal::round((string) $x->now_out, 2).'.';
        }

        return $problems;
    }

    private function allow(User $by, string $permission): void
    {
        if (! $by->can($permission)) {
            throw new AuthorizationException('You are not allowed to do this with a bank statement.');
        }
    }
}
