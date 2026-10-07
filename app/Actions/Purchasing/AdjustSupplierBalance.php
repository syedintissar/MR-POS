<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Puts an amount on a supplier's account without a purchase or a payment:
 *  - 'opening_balance'    what the shop already owed (or was owed) when it started using the system; once per supplier
 *  - 'balance_adjustment' a correction, always with a written reason
 *
 * Direction 'we_owe' makes the balance larger (we owe the supplier more); 'they_owe' makes it smaller (the supplier owes us / holds our advance).
 * Restricted by permission (suppliers.adjust_balance, Administrator only) and re-confirmed with the password in the controller.
 */
class AdjustSupplierBalance
{
    public const TYPES = ['opening_balance', 'balance_adjustment'];

    public function __construct(
        private readonly SupplierAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Supplier $supplier, string $type, string $direction, string $amount, string $reason, User $by, ?string $date = null): Supplier
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('Unknown type of adjustment.');
        }
        if (! in_array($direction, ['we_owe', 'they_owe'], true)) {
            throw new DomainException('Say whether the shop owes the supplier or the supplier owes the shop.');
        }
        $amount = Decimal::round($amount, 2);
        if (! Decimal::isPositive($amount)) {
            throw new DomainException('Enter an amount greater than zero.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please write the reason for this adjustment.');
        }

        return DB::transaction(function () use ($supplier, $type, $direction, $amount, $reason, $by, $date) {
            $locked = $this->accounts->lockSupplier($supplier->getKey());

            if ($type === 'opening_balance' && $locked->ledgerEntries()->where('entry_type', 'opening_balance')->exists()) {
                throw new DomainException("{$locked->name} already has an opening balance. Use a balance adjustment to correct it.");
            }

            $before = (string) $locked->getRawOriginal('balance');
            $weOwe = $direction === 'we_owe';
            $this->accounts->postEntry($locked, $type, $weOwe ? '0' : $amount, $weOwe ? $amount : '0', $locked,
                ($type === 'opening_balance' ? 'Opening balance' : 'Balance adjustment').": {$reason}", $by, $date);

            if ($type === 'opening_balance') {
                $locked->forceFill(['opening_balance' => $weOwe ? $amount : Decimal::neg($amount, 2)])->saveQuietly();
            }

            $this->audit->log($type === 'opening_balance' ? 'supplier_opening_balance' : 'supplier_balance_adjusted', 'suppliers',
                "{$locked->name}: ".($weOwe ? 'we owe' : 'they owe')." {$amount} ({$reason})", $locked,
                old: ['balance' => $before], new: ['balance' => (string) $locked->getRawOriginal('balance'), 'direction' => $direction, 'amount' => $amount, 'reason' => $reason], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
