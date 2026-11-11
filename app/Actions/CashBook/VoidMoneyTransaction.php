<?php

declare(strict_types=1);

namespace App\Actions\CashBook;

use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a cash & bank entry. It stays on record, marked cancelled with who and why, and no longer counts in any balance.
 * A count difference is not cancelled here: it belongs to its day closing, which is cancelled as a whole.
 */
class VoidMoneyTransaction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(MoneyTransaction $txn, string $reason, User $by): MoneyTransaction
    {
        if (! $by->can('cashbook.void')) {
            throw new AuthorizationException('You are not allowed to cancel cash & bank entries.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the entry.');
        }

        return DB::transaction(function () use ($txn, $reason, $by) {
            $locked = MoneyTransaction::query()->whereKey($txn->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->txn_no} has already been cancelled.");
            }
            if ($locked->isFromCount()) {
                throw new DomainException("{$locked->txn_no} was written by a day closing. Cancel the day closing instead.");
            }

            $locked->forceFill(['status' => MoneyTransaction::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            $this->audit->log('money_transaction_voided', 'cashbook', "{$locked->txn_no} ({$locked->label()} of {$locked->amount}) cancelled: {$reason}", $locked, new: ['reason' => $reason, 'amount' => (string) $locked->amount], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
