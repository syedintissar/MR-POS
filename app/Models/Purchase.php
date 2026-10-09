<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    public const DRAFT = 'draft';

    public const RECEIVED = 'received';

    public const VOIDED = 'voided';

    /** Status and money caches are written only by the purchase actions and SupplierAccountService. */
    protected $guarded = ['id', 'status', 'purchase_no', 'paid_amount', 'returned_amount', 'due_amount', 'payment_status', 'received_at', 'received_by', 'voided_at', 'voided_by', 'void_reason'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date', 'received_at' => 'datetime', 'voided_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'additional_cost' => 'decimal:2',
            'grand_total' => 'decimal:2', 'paid_amount' => 'decimal:2', 'returned_amount' => 'decimal:2', 'due_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class)->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::RECEIVED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }

    /** "PUR-000012" once received, "Draft #7" before. */
    public function label(): string
    {
        return $this->purchase_no ?? 'Draft #'.$this->id;
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->where('purchase_no', 'like', $like)->orWhere('supplier_invoice_no', 'like', $like));
    }

    /** Received bills that still owe money, oldest first (the order payments settle them). */
    public function scopeOwing(Builder $q): Builder
    {
        return $q->where('status', self::RECEIVED)->where('due_amount', '>', 0)->orderBy('purchase_date')->orderBy('id');
    }

    public static function paymentStatusFor(string $due, string $paid): string
    {
        if (bccomp($due, '0', 2) <= 0) {
            return 'paid';
        }

        return bccomp($paid, '0', 2) > 0 ? 'partial' : 'unpaid';
    }
}
