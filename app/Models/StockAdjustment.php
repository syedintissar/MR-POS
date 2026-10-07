<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    public const REASONS = [
        'opening' => 'Opening stock (first entry)',
        'count_correction' => 'Stock count correction',
        'damage' => 'Damaged',
        'loss' => 'Lost or stolen',
        'found' => 'Found',
        'expiry' => 'Expired',
        'other' => 'Other',
    ];

    protected $fillable = ['adjustment_no', 'adjustment_date', 'reason', 'notes', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['adjustment_date' => 'date', 'voided_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
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

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
