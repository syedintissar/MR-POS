<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cheque in the register. The register follows the cheque (its date, whether it cleared or bounced); the money is recorded by
 * the payment it came with. A payment made with the "Cheque" method adds its cheque here by itself.
 */
class Cheque extends Model
{
    public const RECEIVED = 'received';

    public const GIVEN = 'given';

    public const PENDING = 'pending';

    public const STATUSES = ['pending', 'cleared', 'bounced', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cheque_date' => 'date', 'status_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Pending, and the date on it has come: it can be presented to the bank (or the supplier can present ours). */
    public function isDue(): bool
    {
        return $this->isPending() && ! $this->cheque_date->isFuture();
    }

    /** A payment was saved: when it was made by cheque, its cheque goes into the register (once). */
    public static function recordFor(CustomerPayment|SupplierPayment $payment): void
    {
        $method = PaymentMethod::query()->find($payment->payment_method_id);
        if (! $method || $method->code !== 'cheque' || $payment->status !== 'posted') {
            return;
        }
        $isCustomer = $payment instanceof CustomerPayment;
        $key = $isCustomer ? 'customer_payment_id' : 'supplier_payment_id';
        if (self::query()->where($key, $payment->id)->exists()) {
            return;
        }
        $party = $isCustomer ? Customer::query()->withTrashed()->find($payment->customer_id) : Supplier::query()->withTrashed()->find($payment->supplier_id);
        // money coming in is a cheque received: a receipt from a customer, or a supplier paying the shop back
        $incoming = $isCustomer ? $payment->type === 'receipt' : $payment->type === 'refund';

        self::query()->create([
            'direction' => $incoming ? self::RECEIVED : self::GIVEN,
            'customer_id' => $isCustomer ? $payment->customer_id : null, 'supplier_id' => $isCustomer ? null : $payment->supplier_id,
            'party_name' => (string) ($party?->name ?? 'Unknown'), 'cheque_no' => $payment->reference_no, 'amount' => $payment->amount,
            'cheque_date' => $payment->payment_date, 'status' => self::PENDING, $key => $payment->id, 'created_by' => $payment->created_by,
            'notes' => 'From '.($isCustomer ? 'receipt '.$payment->receipt_no : 'payment '.$payment->payment_no),
        ]);
    }

    /** The payment was cancelled: a cheque of it that is still pending is cancelled with it. */
    public static function cancelFor(CustomerPayment|SupplierPayment $payment): void
    {
        self::query()->where($payment instanceof CustomerPayment ? 'customer_payment_id' : 'supplier_payment_id', $payment->id)->where('status', self::PENDING)
            ->update(['status' => 'cancelled', 'status_date' => now()->toDateString(), 'updated_at' => now()]);
    }
}
