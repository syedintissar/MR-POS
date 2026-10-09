<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\ServiceJob;
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
            foreach ($plan as [$doc, $part]) {
                $this->accounts->allocateAdvance($c->id, $doc, $part);   // also refreshes the invoice or job
                $applied[] = ['invoice' => $doc instanceof Sale ? $doc->invoice_no : $doc->invoice_no.' ('.$doc->job_no.')', 'amount' => $part];
            }

            $total = array_reduce($applied, fn ($carry, $a) => Decimal::add($carry, $a['amount'], 2), '0.00');
            $this->audit->log('customer_advance_applied', 'customers', "Advance of {$c->name} ({$total}) applied to ".count($applied).' invoice(s)', $c, new: [
                'applied' => $applied, 'advance_left' => Decimal::sub($advance, $total, 2),
            ], user: $by);

            return $applied;
        }, 3);
    }

    /** @return list<array{0: Sale|ServiceJob, 1: string}> */
    private function auto(int $customerId, string $advance): array
    {
        $plan = [];
        $left = $advance;
        foreach ($this->accounts->openDocuments($customerId, true) as $doc) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $part = Decimal::cmp($left, (string) $doc->due_amount) < 0 ? $left : Decimal::round((string) $doc->due_amount, 2);
            $plan[] = [$doc, $part];
            $left = Decimal::sub($left, $part, 2);
        }

        return $plan;
    }

    /**
     * @param  array<int|string, string>  $allocations  "12" => amount for invoice 12, "j7" => amount for service job 7
     * @return list<array{0: Sale|ServiceJob, 1: string}>
     */
    private function manual(int $customerId, string $advance, array $allocations): array
    {
        $wanted = [];
        foreach ($allocations as $key => $part) {
            $part = Decimal::round((string) $part, 2);
            if (Decimal::isNegative($part)) {
                throw new DomainException('An invoice cannot be paid a negative amount.');
            }
            if (Decimal::isPositive($part)) {
                $wanted[(string) $key] = $part;
            }
        }
        $total = array_reduce($wanted, fn ($c, $p) => Decimal::add($c, $p, 2), '0.00');
        if (Decimal::cmp($total, $advance) > 0) {
            throw new DomainException("Only {$advance} of advance is available, not {$total}.");
        }

        $saleIds = [];
        $jobIds = [];
        foreach (array_keys($wanted) as $key) {
            $key = (string) $key;
            if (preg_match('/^j(\d+)$/', $key, $m)) {
                $jobIds[] = (int) $m[1];
            } elseif (ctype_digit($key)) {
                $saleIds[] = (int) $key;
            } else {
                throw new DomainException('One of the invoices is not valid.');
            }
        }
        $sales = Sale::query()->whereIn('id', $saleIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $jobs = ServiceJob::query()->whereIn('id', $jobIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $plan = [];
        foreach ($wanted as $key => $part) {
            $key = (string) $key;
            $isJob = $key[0] === 'j';
            $doc = $isJob ? ($jobs[(int) substr($key, 1)] ?? null) : ($sales[(int) $key] ?? null);
            if (! $doc || $doc->customer_id !== $customerId) {
                throw new DomainException('One of the invoices does not belong to this customer.');
            }
            if ($isJob ? $doc->status !== ServiceJob::DELIVERED : $doc->isVoided()) {
                throw new DomainException($isJob ? "Service job {$doc->job_no} has not been invoiced (or was cancelled), so it cannot be paid here." : "{$doc->invoice_no} has been cancelled.");
            }
            if (Decimal::cmp($part, (string) $doc->due_amount) > 0) {
                throw new DomainException("{$doc->invoice_no} has only {$doc->due_amount} left to pay, not {$part}.");
            }
            $plan[] = [$doc, $part];
        }

        return $plan;
    }
}
