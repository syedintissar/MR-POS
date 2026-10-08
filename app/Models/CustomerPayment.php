<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money received from (or paid back to) a customer. Every payment taken at the till is one of these, settling one sale. */
class CustomerPayment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payment_date' => 'datetime', 'voided_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** The sale this receipt was taken for, when it was taken at the till (collected payments have none). */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
