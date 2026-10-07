<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Applies money already paid to a supplier (an advance: the unallocated part of a payment, or a payment left over from a
 * cancelled bill) to bills that are still open. No money moves and the supplier's balance does not change, so there is no
 * ledger line: only the bills' paid / due figures change.
 */
class AllocateSupplierAdvance
{
    public function __construct(
        private readonly SupplierAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<int|string, string>  $allocations  purchase_id => amount */
    public function execute(SupplierPayment $payment, array $allocations, User $by): SupplierPayment
    {
        $supplierId = SupplierPayment::query()->whereKey($payment->getKey())->value('supplier_id');
        if ($supplierId === null) {
            throw new DomainException('This payment no longer exists.');
        }

        $wanted = [];
        foreach ($allocations as $purchaseId => $part) {
            $part = Decimal::round($part, 2);
            if (Decimal::isNegative($part)) {
                throw new DomainException('A bill cannot be paid a negative amount.');
            }
            if (Decimal::isPositive($part)) {
                $wanted[(int) $purchaseId] = $part;
            }
        }
        if ($wanted === []) {
            throw new DomainException('Enter an amount for at least one bill.');
        }

        return DB::transaction(function () use ($payment, $supplierId, $wanted, $by) {
            $supplier = $this->accounts->lockSupplier((int) $supplierId);
            $locked = SupplierPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoided() || $locked->type !== 'payment') {
                throw new DomainException("{$locked->payment_no} is cancelled and has nothing to apply.");
            }

            $available = $locked->advanceAmount();
            $total = array_reduce($wanted, fn ($c, $p) => Decimal::add($c, $p, 2), '0.00');
            if (Decimal::cmp($total, $available) > 0) {
                throw new DomainException("Only {$available} of {$locked->payment_no} is still unapplied, not {$total}.");
            }

            $purchases = Purchase::query()->whereIn('id', array_keys($wanted))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $applied = [];
            foreach ($wanted as $purchaseId => $part) {
                $purchase = $purchases[$purchaseId] ?? null;
                if (! $purchase || $purchase->supplier_id !== $supplier->id) {
                    throw new DomainException('One of the bills does not belong to this supplier.');
                }
                if (! $purchase->isReceived()) {
                    throw new DomainException("{$purchase->label()} is not an open bill (it is {$purchase->status}).");
                }
                if (Decimal::cmp($part, (string) $purchase->due_amount) > 0) {
                    throw new DomainException("{$purchase->label()} has only {$purchase->due_amount} left to pay, not {$part}.");
                }

                $row = PurchasePayment::query()->where('supplier_payment_id', $locked->id)->where('purchase_id', $purchaseId)->lockForUpdate()->first();
                if ($row) {
                    $row->update(['amount' => Decimal::add((string) $row->amount, $part, 2)]);
                } else {
                    PurchasePayment::query()->create(['supplier_payment_id' => $locked->id, 'purchase_id' => $purchaseId, 'amount' => $part]);
                }

                $this->accounts->refreshPurchase($purchase);
                $applied[] = ['purchase' => $purchase->purchase_no, 'amount' => $part];
            }

            $this->audit->log('supplier_advance_applied', 'purchasing', "Advance from {$locked->payment_no} ({$total}) applied to ".count($applied).' bill(s) of '.$supplier->name, $locked, new: [
                'applied' => $applied, 'unapplied_left' => Decimal::sub($available, $total, 2),
            ], user: $by);

            return $locked->load('allocations.purchase', 'method', 'supplier');
        }, 3);
    }
}
