<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Puts an amount on a customer's account without a sale or a payment:
 *  - 'opening_balance'    what the customer already owed (or had paid in advance) when the shop started using the system; once per customer
 *  - 'balance_adjustment' a correction, always with a written reason
 *
 * Direction 'customer_owes' makes the balance larger (the customer owes more); 'shop_owes' makes it smaller (the shop holds the customer's money).
 * Administrator only (customers.adjust_balance), with the password re-entered in the controller.
 */
class AdjustCustomerBalance
{
    public const TYPES = ['opening_balance', 'balance_adjustment'];

    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Customer $customer, string $type, string $direction, string $amount, string $reason, User $by, ?string $date = null): Customer
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('Unknown type of adjustment.');
        }
        if (! in_array($direction, ['customer_owes', 'shop_owes'], true)) {
            throw new DomainException('Say whether the customer owes the shop or the shop holds the customer\'s money.');
        }
        $amount = Decimal::round($amount, 2);
        if (! Decimal::isPositive($amount)) {
            throw new DomainException('Enter an amount greater than zero.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please write the reason for this adjustment.');
        }

        return DB::transaction(function () use ($customer, $type, $direction, $amount, $reason, $by, $date) {
            $locked = $this->accounts->lockCustomer($customer->getKey());
            if ($locked->is_walk_in) {
                throw new DomainException('The walk-in customer has no account.');
            }
            if ($type === 'opening_balance' && $locked->ledgerEntries()->where('entry_type', 'opening_balance')->exists()) {
                throw new DomainException("{$locked->name} already has an opening balance. Use a balance adjustment to correct it.");
            }

            $before = (string) $locked->getRawOriginal('balance');
            $owes = $direction === 'customer_owes';
            $this->accounts->postEntry($locked, $type, $owes ? $amount : '0', $owes ? '0' : $amount, $locked,
                ($type === 'opening_balance' ? 'Opening balance' : 'Balance adjustment').": {$reason}", $by, $date);

            if ($type === 'opening_balance') {
                $locked->forceFill(['opening_balance' => $owes ? $amount : Decimal::neg($amount, 2)])->saveQuietly();
            }

            $this->audit->log($type === 'opening_balance' ? 'customer_opening_balance' : 'customer_balance_adjusted', 'customers',
                "{$locked->name}: ".($owes ? 'customer owes' : 'shop holds')." {$amount} ({$reason})", $locked,
                old: ['balance' => $before], new: ['balance' => (string) $locked->getRawOriginal('balance'), 'direction' => $direction, 'amount' => $amount, 'reason' => $reason], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
