<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A completed (or voided) sale. Nothing is mass-assignable: the sale actions write every column deliberately. */
class Sale extends Model
{
    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sale_date' => 'datetime', 'voided_at' => 'datetime', 'tax_inclusive' => 'boolean', 'print_notes' => 'boolean',
            'items_total' => 'decimal:2', 'line_discount_total' => 'decimal:2', 'invoice_discount_amount' => 'decimal:2',
            'invoice_discount_percent' => 'decimal:2', 'tax_total' => 'decimal:2', 'round_off' => 'decimal:2', 'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'returned_amount' => 'decimal:2', 'due_amount' => 'decimal:2',
            'amount_tendered' => 'decimal:2', 'change_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->where('status', self::COMPLETED);
    }

    /** Invoice number, or the customer's name / phone. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $q->where('invoice_no', 'like', $like);
    }

    public static function paymentStatusFor(string $due, string $paid): string
    {
        if (bccomp($due, '0', 2) <= 0) {
            return 'paid';
        }

        return bccomp($paid, '0', 2) > 0 ? 'partial' : 'unpaid';
    }
}
