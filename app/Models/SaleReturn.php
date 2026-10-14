<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods a customer brought back against an invoice. Nothing is mass-assignable: the return actions write every column deliberately. */
class SaleReturn extends Model
{
    public const POSTED = 'posted';

    public const VOIDED = 'voided';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'return_date' => 'datetime', 'voided_at' => 'datetime', 'net_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'restocking_fee' => 'decimal:2',
            'applied_to_due' => 'decimal:2', 'refund_amount' => 'decimal:2', 'store_credit_amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function exchangeSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'exchange_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class)->orderBy('id');
    }

    public function refundPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'refund_payment_id');
    }

    public function creditPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'store_credit_payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }
}
