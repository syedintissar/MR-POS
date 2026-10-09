<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Models\Expense;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Cancels an expense (EXP-03). It stays on record, marked cancelled with who and why, and no longer counts in any total. */
class VoidExpense
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Expense $expense, string $reason, User $by): Expense
    {
        if (! $by->can('expenses.void')) {
            throw new AuthorizationException('You are not allowed to cancel expenses.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the expense.');
        }

        return DB::transaction(function () use ($expense, $reason, $by) {
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->expense_no} has already been cancelled.");
            }

            $locked->forceFill(['status' => Expense::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            $this->audit->log('expense_voided', 'expenses', "Expense {$locked->expense_no} of {$locked->amount} cancelled: {$reason}", $locked, new: ['reason' => $reason, 'amount' => (string) $locked->amount], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
