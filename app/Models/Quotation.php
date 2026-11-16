<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A price offer. No stock, no money: it becomes a sale only through the till. */
class Quotation extends Model
{
    public const OPEN = 'open';

    public const ACCEPTED = 'accepted';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'quotation_no', 'status', 'sale_id', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'quote_date' => 'date', 'valid_until' => 'date', 'accepted_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'grand_total' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** Still open, but the date it was valid until has passed. It can still be sold: the till uses the quoted prices. */
    public function isExpired(): bool
    {
        return $this->isOpen() && $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    public function statusLabel(): string
    {
        return $this->isExpired() ? 'Expired' : ucfirst($this->status);
    }
}
