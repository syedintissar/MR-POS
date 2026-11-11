<?php

declare(strict_types=1);

namespace App\Actions\CashBook;

use App\Models\DayClosing;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CashBook\MoneyAccountService;
use App\Services\DocumentNumberService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Closes the day for a cash account: the money counted in the drawer is compared with what the book says should be there.
 *
 * The count is the truth. A difference is recorded with its explanation as a "cash over" or "cash short" entry, so that the book
 * agrees with the drawer again and the next day starts from what is really there. Short and over stay visible for ever
 * (who counted, when, how much, why); they are not hidden in an expense.
 */
class CloseDay
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly MoneyAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Either the notes are counted one kind at a time (`denominations`: note value => how many, plus `coins`: an amount),
     * or the total is typed as `counted_amount`. When any note count is given, the total is worked out from the notes.
     *
     * @param  array{denominations?: array<int|string, mixed>|null, counted_amount?: string|null, notes?: string|null}  $d
     */
    public function execute(MoneyAccount $account, array $d, User $by): DayClosing
    {
        if (! $by->can('cashbook.close_day')) {
            throw new AuthorizationException('You are not allowed to close the day.');
        }

        [$counted, $denominations] = $this->counted($d);
        $notes = trim((string) ($d['notes'] ?? ''));

        return DB::transaction(function () use ($account, $counted, $denominations, $notes, $by) {
            $locked = MoneyAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isCash()) {
                throw new DomainException("Only a cash account is counted. Check \"{$locked->name}\" against its bank or wallet statement instead.");
            }
            if (! $locked->is_active) {
                throw new DomainException("\"{$locked->name}\" is switched off.");
            }

            $expected = $this->accounts->balance($locked->id);
            $difference = Decimal::sub($counted, $expected, 2);
            if (! Decimal::isZero($difference) && $notes === '') {
                throw new DomainException('The count differs from the book by '.Decimal::abs($difference, 2).' ('.(Decimal::isPositive($difference) ? 'over' : 'short').'). Write what explains it, or count again.');
            }

            $closingNo = $this->numbers->next('day_closing');

            $adjustment = null;
            if (! Decimal::isZero($difference)) {
                $over = Decimal::isPositive($difference);
                $adjustment = new MoneyTransaction;
                $adjustment->forceFill([
                    'txn_no' => $this->numbers->next('money_transaction'), 'type' => $over ? 'count_over' : 'count_short', 'txn_date' => now()->toDateString(),
                    'from_account_id' => $over ? null : $locked->id, 'to_account_id' => $over ? $locked->id : null, 'amount' => Decimal::abs($difference, 2),
                    'description' => mb_substr("Day closing {$closingNo}: {$notes}", 0, 255), 'status' => MoneyTransaction::POSTED, 'created_by' => $by->id,
                ])->save();
            }

            $closing = new DayClosing;
            $closing->forceFill([
                'closing_no' => $closingNo, 'money_account_id' => $locked->id, 'business_date' => now()->toDateString(), 'closed_at' => now(),
                'expected_amount' => $expected, 'counted_amount' => $counted, 'difference' => $difference, 'denominations' => $denominations,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null, 'adjustment_transaction_id' => $adjustment?->id,
                'status' => DayClosing::POSTED, 'created_by' => $by->id,
            ])->save();

            $this->audit->log('day_closed', 'cashbook', "{$closingNo}: {$locked->name} counted {$counted}, the book said {$expected}".(Decimal::isZero($difference) ? ' (exact)' : ', difference '.$difference), $closing, new: [
                'account' => $locked->name, 'expected' => $expected, 'counted' => $counted, 'difference' => $difference, 'notes' => $notes ?: null, 'entry' => $adjustment?->txn_no,
            ], user: $by);

            $this->accounts->forgetReminder();

            return $closing->refresh();
        }, 3);
    }

    /** @return array{0: string, 1: array<string, mixed>|null} the counted total and the note counts that made it (null when the total was typed) */
    private function counted(array $d): array
    {
        $given = is_array($d['denominations'] ?? null) ? $d['denominations'] : [];
        $counts = [];
        $total = '0.00';
        $any = false;
        foreach (DayClosing::NOTES as $note) {
            $n = $given[$note] ?? $given[(string) $note] ?? null;
            if ($n === null || $n === '') {
                continue;
            }
            if (! is_numeric($n) || (int) $n != $n || (int) $n < 0 || (int) $n > 1000000) {
                throw new DomainException("Enter how many Rs. {$note} notes there are as a whole number.");
            }
            if ((int) $n > 0) {
                $counts[(string) $note] = (int) $n;
                $total = Decimal::add($total, Decimal::mul((string) $note, (string) (int) $n, 2), 2);
            }
            $any = true;
        }
        $coins = $given['coins'] ?? null;
        if ($coins !== null && $coins !== '') {
            if (! Decimal::isNumber($coins) || Decimal::isNegative((string) $coins)) {
                throw new DomainException('Enter the coins and small notes as an amount.');
            }
            $coins = Decimal::round((string) $coins, 2);
            if (! Decimal::isZero($coins)) {
                $counts['coins'] = $coins;
                $total = Decimal::add($total, $coins, 2);
            }
            $any = true;
        }
        if ($any) {
            return [$total, $counts];
        }

        $typed = $d['counted_amount'] ?? null;
        if ($typed === null || $typed === '' || ! Decimal::isNumber($typed) || Decimal::isNegative((string) $typed)) {
            throw new DomainException('Count the notes, or enter the total that is in the drawer.');
        }

        return [Decimal::round((string) $typed, 2), null];
    }
}
