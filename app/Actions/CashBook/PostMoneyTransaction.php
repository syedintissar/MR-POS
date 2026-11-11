<?php

declare(strict_types=1);

namespace App\Actions\CashBook;

use App\Models\BankReconciliation;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CashBook\MoneyAccountService;
use App\Services\DocumentNumberService;
use App\Support\Decimal;
use App\Support\Money;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Records money that moved without a sale, a purchase or an expense: cash taken to the bank (a transfer), money the owner took out or put in,
 * or what an account held on the day the shop started using the book (its opening balance, once per account).
 * None of these is income or a cost, so profit is not touched.
 */
class PostMoneyTransaction
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly MoneyAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{type: string, txn_date: string, amount: string, from_account_id?: int|string|null, to_account_id?: int|string|null, reference_no?: string|null, description?: string|null}  $d
     */
    public function execute(array $d, User $by): MoneyTransaction
    {
        $type = (string) ($d['type'] ?? '');
        if (! array_key_exists($type, MoneyTransaction::ENTERED_TYPES)) {
            throw new DomainException('Choose what kind of entry this is.');
        }
        if (! $by->can($type === 'opening_balance' ? 'cashbook.manage' : 'cashbook.transact')) {
            throw new AuthorizationException($type === 'opening_balance' ? 'You are not allowed to enter opening balances.' : 'You are not allowed to record cash & bank entries.');
        }

        $amount = Decimal::isNumber($d['amount'] ?? null) ? Decimal::round((string) $d['amount'], 2) : '0.00';
        if (! Decimal::isPositive($amount)) {
            throw new DomainException('Enter an amount greater than zero.');
        }
        $date = (string) ($d['txn_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $date) || strtotime($date) === false) {
            throw new DomainException('Enter the date of the entry.');
        }
        $date = date('Y-m-d', strtotime($date));
        if ($date > now()->toDateString()) {
            throw new DomainException('The date of an entry cannot be in the future.');
        }

        $needsFrom = in_array($type, ['transfer', 'owner_out'], true);
        $needsTo = in_array($type, ['transfer', 'owner_in', 'opening_balance'], true);
        $fromId = $needsFrom ? (int) ($d['from_account_id'] ?? 0) : null;
        $toId = $needsTo ? (int) ($d['to_account_id'] ?? 0) : null;
        if ($needsFrom && $fromId === 0) {
            throw new DomainException('Choose the account the money leaves.');
        }
        if ($needsTo && $toId === 0) {
            throw new DomainException('Choose the account the money goes into.');
        }
        if ($type === 'transfer' && $fromId === $toId) {
            throw new DomainException('Choose two different accounts for a transfer.');
        }

        $description = trim((string) ($d['description'] ?? ''));
        if ($description === '') {
            $description = ['transfer' => 'Transfer between accounts', 'owner_out' => 'Taken out by the owner', 'owner_in' => 'Put in by the owner', 'opening_balance' => 'Opening balance'][$type];
        }
        $reference = trim((string) ($d['reference_no'] ?? ''));

        return DB::transaction(function () use ($type, $amount, $date, $fromId, $toId, $description, $reference, $by) {
            // the accounts are locked in id order, so two people moving money between the same accounts cannot block each other for ever
            $locked = MoneyAccount::query()->whereIn('id', array_filter([$fromId, $toId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $fromId ? $locked->get($fromId) : null;
            $to = $toId ? $locked->get($toId) : null;
            if (($fromId && ! $from) || ($toId && ! $to)) {
                throw new DomainException('That account does not exist.');
            }
            foreach (array_filter([$from, $to]) as $account) {
                if (! $account->is_active) {
                    throw new DomainException("\"{$account->name}\" is switched off. Switch it on again to use it.");
                }
            }

            // a statement that has been matched and closed is not reopened by an entry dated inside it
            foreach (array_filter([$from, $to]) as $account) {
                $closedTo = BankReconciliation::query()->where('money_account_id', $account->id)->where('status', BankReconciliation::COMPLETED)->max('statement_date');
                if ($closedTo !== null && $date <= substr((string) $closedTo, 0, 10)) {
                    throw new DomainException("The statement of \"{$account->name}\" has been matched up to ".date('d M Y', strtotime((string) $closedTo)).'. Date the entry after that day.');
                }
            }

            if ($type === 'opening_balance' && MoneyTransaction::query()->where('type', 'opening_balance')->where('to_account_id', $to->id)->where('status', MoneyTransaction::POSTED)->exists()) {
                throw new DomainException("\"{$to->name}\" already has an opening balance. Cancel that entry first if it was wrong.");
            }

            // cash cannot leave a drawer that does not hold it; a bank account may be overdrawn, so only cash is checked
            if ($from && $from->isCash()) {
                $holds = $this->accounts->balance($from->id);
                if (Decimal::cmp($amount, $holds) > 0) {
                    throw new DomainException("\"{$from->name}\" holds ".Money::withSymbol($holds).' in the book, less than '.Money::withSymbol($amount).'. If there really is more cash, count the drawer first (Close the day) or enter its opening balance.');
                }
            }

            $txn = new MoneyTransaction;
            $txn->forceFill([
                'txn_no' => $this->numbers->next('money_transaction'), 'type' => $type, 'txn_date' => $date, 'from_account_id' => $from?->id, 'to_account_id' => $to?->id,
                'amount' => $amount, 'reference_no' => $reference !== '' ? mb_substr($reference, 0, 100) : null, 'description' => mb_substr($description, 0, 255),
                'status' => MoneyTransaction::POSTED, 'created_by' => $by->id,
            ])->save();

            $this->audit->log('money_transaction_posted', 'cashbook', "{$txn->txn_no}: {$txn->label()} of {$amount}".($from ? " from {$from->name}" : '').($to ? " to {$to->name}" : ''), $txn, new: [
                'type' => $type, 'amount' => $amount, 'date' => $date, 'from' => $from?->name, 'to' => $to?->name, 'reference' => $reference ?: null, 'description' => $description,
            ], user: $by);

            return $txn->refresh();
        }, 3);
    }
}
