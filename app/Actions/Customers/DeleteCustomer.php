<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Deletes a customer that has never been used. A customer with any invoice, payment or ledger line can only be deactivated. */
class DeleteCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Customer $customer, User $by): void
    {
        DB::transaction(function () use ($customer, $by) {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->is_walk_in) {
                throw new DomainException('The walk-in customer is part of the system and cannot be deleted.');
            }
            if ($locked->hasHistory()) {
                throw new DomainException('This customer has invoices, payments or an account history, so it cannot be deleted. Deactivate it instead.');
            }

            $this->audit->log('customer_deleted', 'customers', "Customer {$locked->code} · {$locked->name} deleted", $locked, old: $locked->only(['code', 'name', 'phone']), user: $by);
            $locked->delete();
        }, 3);
    }
}
