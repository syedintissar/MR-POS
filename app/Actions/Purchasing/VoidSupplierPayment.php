<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Purchasing\SupplierAccountService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a payment that was recorded by mistake. The ledger gets an opposite line (nothing is deleted), and every bill the
 * payment had settled becomes unpaid again for that part.
 */
class VoidSupplierPayment
{
    public function __construct(
        private readonly SupplierAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SupplierPayment $payment, string $reason, User $by): SupplierPayment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the payment.');
        }

        $supplierId = SupplierPayment::query()->whereKey($payment->getKey())->value('supplier_id');
        if ($supplierId === null) {
            throw new DomainException('This payment no longer exists.');
        }

        return DB::transaction(function () use ($payment, $supplierId, $reason, $by) {
            $supplier = $this->accounts->lockSupplier((int) $supplierId);
            $locked = SupplierPayment::query()->with('method')->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new DomainException("{$locked->payment_no} has already been cancelled.");
            }
            if ($locked->type !== 'payment') {
                throw new DomainException('Only a payment to a supplier can be cancelled here.');
            }

            $purchaseIds = $locked->allocations()->orderBy('purchase_id')->pluck('purchase_id')->all();
            $purchases = Purchase::query()->whereIn('id', $purchaseIds)->orderBy('id')->lockForUpdate()->get();

            $locked->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();

            foreach ($purchases as $purchase) {
                $this->accounts->refreshPurchase($purchase);
            }

            $this->accounts->postEntry($supplier, 'payment_void', '0', (string) $locked->amount, $locked, "Payment {$locked->payment_no} cancelled: {$reason}", $by);

            $this->audit->log('supplier_payment_voided', 'purchasing', "Payment {$locked->payment_no} to {$supplier->name} cancelled: {$reason}", $locked, new: [
                'reason' => $reason, 'amount' => (string) $locked->amount, 'bills_reopened' => $purchases->pluck('purchase_no')->all(),
            ], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
