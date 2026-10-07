<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records money paid to a supplier.
 *
 *  allocation 'auto'    : settles the oldest unpaid bills first; whatever is left over stays with the supplier as an advance
 *  allocation 'manual'  : settles exactly the bills (and amounts) given in `allocations` [purchase_id => amount]
 *  allocation 'advance' : settles nothing; the whole amount is an advance to be applied to bills later
 *
 * All-or-nothing. The supplier row is locked first, so two cashiers paying the same supplier (or the same bill) at the same
 * moment are handled one after the other and can never settle a bill twice.
 */
class PostSupplierPayment
{
    public function __construct(
        private readonly SupplierAccountService $accounts,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id: int, payment_date?: string|null, payment_method_id: int, amount: string, reference_no?: string|null, notes?: string|null, allocation?: string, allocations?: array<int|string, string>}  $data
     */
    public function execute(array $data, User $by): SupplierPayment
    {
        $amount = Decimal::round($data['amount'], 2);
        if (! Decimal::isPositive($amount)) {
            throw new DomainException('Enter an amount greater than zero.');
        }

        $mode = $data['allocation'] ?? 'auto';
        if (! in_array($mode, ['auto', 'manual', 'advance'], true)) {
            throw new DomainException('Unknown way of applying the payment.');
        }

        return DB::transaction(function () use ($data, $amount, $mode, $by) {
            $supplier = $this->accounts->lockSupplier((int) $data['supplier_id']);

            $method = PaymentMethod::query()->find((int) $data['payment_method_id']);
            if (! $method || ! $method->is_active || $method->kind === 'customer_credit') {
                throw new DomainException('Choose a payment method that can be used to pay a supplier.');
            }
            $reference = isset($data['reference_no']) && trim((string) $data['reference_no']) !== '' ? trim((string) $data['reference_no']) : null;
            if ($method->requires_reference && $reference === null) {
                throw new DomainException("{$method->name} payments need a reference (transaction, cheque or slip number).");
            }

            $plan = match ($mode) {
                'auto' => $this->planAuto($supplier->id, $amount),
                'manual' => $this->planManual($supplier->id, $amount, $data['allocations'] ?? []),
                default => [],
            };

            $payment = new SupplierPayment;
            $payment->forceFill([
                'payment_no' => $this->numbers->next('supplier_payment'),
                'supplier_id' => $supplier->id,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'payment_method_id' => $method->id,
                'type' => 'payment',
                'amount' => $amount,
                'reference_no' => $reference,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 255) : null,
                'status' => 'posted',
                'created_by' => $by->id,
            ])->save();

            $settled = [];
            foreach ($plan as $purchaseId => [$purchase, $part]) {
                PurchasePayment::query()->create(['supplier_payment_id' => $payment->id, 'purchase_id' => $purchaseId, 'amount' => $part]);
                $this->accounts->refreshPurchase($purchase);
                $settled[] = ['purchase' => $purchase->purchase_no, 'amount' => $part];
            }

            $this->accounts->postEntry($supplier, 'payment', $amount, '0', $payment,
                "Payment {$payment->payment_no} · {$method->name}".($reference ? " · {$reference}" : ''), $by, $payment->payment_date->toDateString());

            $advance = Decimal::sub($amount, array_reduce($plan, fn ($c, $p) => Decimal::add($c, $p[1], 2), '0.00'), 2);
            $this->audit->log('supplier_payment_posted', 'purchasing', "Payment {$payment->payment_no} of {$amount} to {$supplier->name} ({$method->name})", $payment, new: [
                'supplier' => $supplier->name, 'method' => $method->name, 'amount' => $amount, 'reference' => $reference, 'settled' => $settled, 'advance' => $advance,
            ], user: $by);

            return $payment->load('allocations.purchase', 'method', 'supplier');
        }, 3);
    }

    /** Oldest unpaid bills first. @return array<int, array{0: Purchase, 1: string}> */
    private function planAuto(int $supplierId, string $amount): array
    {
        $plan = [];
        $left = $amount;

        $owing = Purchase::query()->where('supplier_id', $supplierId)->owing()->lockForUpdate()->get();
        foreach ($owing as $purchase) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $part = Decimal::cmp($left, (string) $purchase->due_amount) < 0 ? $left : Decimal::round((string) $purchase->due_amount, 2);
            $plan[$purchase->id] = [$purchase, $part];
            $left = Decimal::sub($left, $part, 2);
        }

        return $plan;
    }

    /**
     * @param  array<int|string, string>  $allocations
     * @return array<int, array{0: Purchase, 1: string}>
     */
    private function planManual(int $supplierId, string $amount, array $allocations): array
    {
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
            throw new DomainException('Enter how much of the payment goes to which bill, or choose "keep as advance".');
        }

        $purchases = Purchase::query()->whereIn('id', array_keys($wanted))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $plan = [];
        $total = '0.00';
        foreach ($wanted as $purchaseId => $part) {
            $purchase = $purchases[$purchaseId] ?? null;
            if (! $purchase || $purchase->supplier_id !== $supplierId) {
                throw new DomainException('One of the bills does not belong to this supplier.');
            }
            if (! $purchase->isReceived()) {
                throw new DomainException("{$purchase->label()} is not an open bill (it is {$purchase->status}).");
            }
            if (Decimal::cmp($part, (string) $purchase->due_amount) > 0) {
                throw new DomainException("{$purchase->label()} has only {$purchase->due_amount} left to pay, not {$part}.");
            }
            $plan[$purchaseId] = [$purchase, $part];
            $total = Decimal::add($total, $part, 2);
        }

        if (Decimal::cmp($total, $amount) > 0) {
            throw new DomainException("The amounts given to the bills ({$total}) are more than the payment ({$amount}).");
        }

        return $plan;
    }
}
