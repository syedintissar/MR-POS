<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\Sales\SaleAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Applies money a customer has already paid in (their advance) to invoices that are still open, oldest receipt first. No money moves and
 * the customer's balance does not change, so there is no ledger line: only the invoices' paid / due figures change.
 */
class AllocateCustomerAdvance
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly SaleAccountService $sales,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int|string, string>|null  $allocations  sale_id => amount; null = the oldest unpaid invoices, as far as the advance reaches
     * @return list<array{invoice: string, amount: string}>
     */
    public function execute(Customer $customer, ?array $allocations, User $by): array
    {
        return DB::transaction(function () use ($customer, $allocations, $by) {
            $c = $this->accounts->lockCustomer($customer->getKey());
            if ($c->is_walk_in) {
                throw new DomainException('The walk-in customer has no account.');
            }

            $advance = $this->accounts->advanceFor($c->id);
            if (! Decimal::isPositive($advance)) {
                throw new DomainException("{$c->name} has no advance to apply.");
            }

            $plan = $allocations === null ? $this->auto($c->id, $advance) : $this->manual($c->id, $advance, $allocations);
            if ($plan === []) {
                throw new DomainException('There is nothing to apply the advance to.');
            }

            $applied = [];
            foreach ($plan as [$sale, $part]) {
                $this->accounts->allocateAdvance($c->id, $sale, $part);
                $this->sales->refreshSale($sale);
                $applied[] = ['invoice' => $sale->invoice_no, 'amount' => $part];
            }

            $total = array_reduce($applied, fn ($carry, $a) => Decimal::add($carry, $a['amount'], 2), '0.00');
            $this->audit->log('customer_advance_applied', 'customers', "Advance of {$c->name} ({$total}) applied to ".count($applied).' invoice(s)', $c, new: [
                'applied' => $applied, 'advance_left' => Decimal::sub($advance, $total, 2),
            ], user: $by);

            return $applied;
        }, 3);
    }

    /** @return list<array{0: Sale, 1: string}> */
    private function auto(int $customerId, string $advance): array
    {
        $plan = [];
        $left = $advance;
        foreach (Sale::query()->where('customer_id', $customerId)->where('status', Sale::COMPLETED)->where('due_amount', '>', 0)->orderBy('sale_date')->orderBy('id')->lockForUpdate()->get() as $sale) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $part = Decimal::cmp($left, (string) $sale->due_amount) < 0 ? $left : Decimal::round((string) $sale->due_amount, 2);
            $plan[] = [$sale, $part];
            $left = Decimal::sub($left, $part, 2);
        }

        return $plan;
    }

    /**
     * @param  array<int|string, string>  $allocations
     * @return list<array{0: Sale, 1: string}>
     */
    private function manual(int $customerId, string $advance, array $allocations): array
    {
        $wanted = [];
        foreach ($allocations as $saleId => $part) {
            $part = Decimal::round((string) $part, 2);
            if (Decimal::isNegative($part)) {
                throw new DomainException('An invoice cannot be paid a negative amount.');
            }
            if (Decimal::isPositive($part)) {
                $wanted[(int) $saleId] = $part;
            }
        }
        $total = array_reduce($wanted, fn ($c, $p) => Decimal::add($c, $p, 2), '0.00');
        if (Decimal::cmp($total, $advance) > 0) {
            throw new DomainException("Only {$advance} of advance is available, not {$total}.");
        }

        $sales = Sale::query()->whereIn('id', array_keys($wanted))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $plan = [];
        foreach ($wanted as $saleId => $part) {
            $sale = $sales[$saleId] ?? null;
            if (! $sale || $sale->customer_id !== $customerId) {
                throw new DomainException('One of the invoices does not belong to this customer.');
            }
            if ($sale->isVoided()) {
                throw new DomainException("{$sale->invoice_no} has been cancelled.");
            }
            if (Decimal::cmp($part, (string) $sale->due_amount) > 0) {
                throw new DomainException("{$sale->invoice_no} has only {$sale->due_amount} left to pay, not {$part}.");
            }
            $plan[] = [$sale, $part];
        }

        return $plan;
    }
}
