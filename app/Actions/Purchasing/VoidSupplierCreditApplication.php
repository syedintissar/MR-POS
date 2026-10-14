<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\SupplierCreditApplication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Purchasing\SupplierAccountService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Takes back one use of supplier credit: the bill owes that amount again and the credit is available again. Nothing is deleted. */
class VoidSupplierCreditApplication
{
    public function __construct(private readonly SupplierAccountService $accounts, private readonly AuditLogger $audit) {}

    public function execute(SupplierCreditApplication $application, string $reason, User $by): SupplierCreditApplication
    {
        if (! $by->can('supplier_payments.void')) {
            throw new AuthorizationException('You are not allowed to cancel a use of supplier credit.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling this use of credit.');
        }

        $supplierId = SupplierCreditApplication::query()->whereKey($application->getKey())->value('supplier_id');
        if ($supplierId === null) {
            throw new DomainException('This use of credit no longer exists.');
        }

        return DB::transaction(function () use ($application, $reason, $by, $supplierId) {
            $supplier = $this->accounts->lockSupplier((int) $supplierId);
            $locked = SupplierCreditApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException('This use of credit has already been cancelled.');
            }
            $bill = Purchase::query()->whereKey($locked->purchase_id)->lockForUpdate()->firstOrFail();

            $locked->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            $this->accounts->refreshPurchase($bill);

            $this->audit->log('supplier_credit_use_cancelled', 'purchasing', "{$locked->amount} of {$supplier->name}'s credit taken back from {$bill->label()}: {$reason}", $supplier, new: [
                'amount' => (string) $locked->amount, 'bill' => $bill->label(), 'reason' => $reason,
            ], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
