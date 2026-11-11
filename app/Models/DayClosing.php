<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The cash drawer counted against the book. Nothing is mass-assignable: the closing actions write every column. */
class DayClosing extends Model
{
    public const POSTED = 'posted';

    public const VOIDED = 'voided';

    /** The notes counted one kind at a time (rupees); coins and small notes are entered as one amount. */
    public const NOTES = [5000, 1000, 500, 100, 50, 20, 10];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date', 'closed_at' => 'datetime', 'expected_amount' => 'decimal:2', 'counted_amount' => 'decimal:2', 'difference' => 'decimal:2',
            'denominations' => 'array', 'voided_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'adjustment_transaction_id');
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

    /** "over", "short" or "exact". */
    public function result(): string
    {
        return Decimal::isZero((string) $this->difference) ? 'exact' : (Decimal::isPositive((string) $this->difference) ? 'over' : 'short');
    }
}
