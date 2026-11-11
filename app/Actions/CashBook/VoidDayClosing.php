<?php

declare(strict_types=1);

namespace App\Actions\CashBook;

use App\Models\DayClosing;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CashBook\MoneyAccountService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a day closing that was counted or typed wrongly, together with the over / short entry it wrote, so the book goes back to
 * what it said before the count. Only the latest closing of an account can be cancelled: an older one is the ground the later ones stand on.
 */
class VoidDayClosing
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(DayClosing $closing, string $reason, User $by): DayClosing
    {
        if (! $by->can('cashbook.void')) {
            throw new AuthorizationException('You are not allowed to cancel a day closing.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the day closing.');
        }

        return DB::transaction(function () use ($closing, $reason, $by) {
            // the account row is the lock every closing of that account takes, so a new closing cannot slip in while this one is cancelled
            MoneyAccount::query()->whereKey($closing->money_account_id)->lockForUpdate()->firstOrFail();
            $locked = DayClosing::query()->whereKey($closing->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->closing_no} has already been cancelled.");
            }
            $latest = DayClosing::query()->where('money_account_id', $locked->money_account_id)->where('status', DayClosing::POSTED)->max('id');
            if ((int) $latest !== (int) $locked->id) {
                throw new DomainException("Only the latest day closing of an account can be cancelled. {$locked->closing_no} has a later one after it.");
            }

            $now = now();
            if ($locked->adjustment_transaction_id !== null) {
                MoneyTransaction::query()->whereKey($locked->adjustment_transaction_id)->lockForUpdate()->firstOrFail()
                    ->forceFill(['status' => MoneyTransaction::VOIDED, 'voided_at' => $now, 'voided_by' => $by->id, 'void_reason' => mb_substr("Day closing {$locked->closing_no} cancelled: {$reason}", 0, 255)])->save();
            }
            $locked->forceFill(['status' => DayClosing::VOIDED, 'voided_at' => $now, 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();

            $this->audit->log('day_closing_voided', 'cashbook', "Day closing {$locked->closing_no} cancelled: {$reason}", $locked, new: ['reason' => $reason, 'difference' => (string) $locked->difference], user: $by);

            app(MoneyAccountService::class)->forgetReminder();

            return $locked->refresh();
        }, 3);
    }
}
