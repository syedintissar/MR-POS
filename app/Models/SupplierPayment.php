<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    protected $guarded = ['id', 'status', 'payment_no', 'voided_at', 'voided_by', 'void_reason'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
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
        return $this->status === 'voided';
    }

    public function scopePosted(Builder $q): Builder
    {
        return $q->where('status', 'posted');
    }

    /** Part of this payment that settles a bill that still stands (allocations to cancelled bills do not count). */
    public function allocatedAmount(): string
    {
        $sum = $this->allocations()->whereHas('purchase', fn ($q) => $q->where('status', '!=', Purchase::VOIDED))->sum('amount');

        return bcadd((string) ($sum ?: '0'), '0', 2);
    }

    /** What is left of this payment: money the supplier holds for us (an advance). */
    public function advanceAmount(): string
    {
        if ($this->isVoided()) {
            return '0.00';
        }

        return bcsub((string) $this->amount, $this->allocatedAmount(), 2);
    }
}
