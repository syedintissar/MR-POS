<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money that moved without a sale, a purchase or an expense: between two accounts, in from or out to the owner,
 * an opening balance, or the difference a count of the drawer found. Nothing is mass-assignable: the actions write every column.
 */
class MoneyTransaction extends Model
{
    public const POSTED = 'posted';

    public const VOIDED = 'voided';

    /** The kinds a person can enter. Opening balances need the "manage" permission. */
    public const ENTERED_TYPES = [
        'transfer' => 'Transfer between accounts (for example cash taken to the bank)',
        'owner_out' => 'Owner took money out',
        'owner_in' => 'Owner put money in',
        'opening_balance' => 'Opening balance (what the account held when you started)',
    ];

    /** Written only by a day closing. */
    public const COUNT_TYPES = ['count_over', 'count_short'];

    public const LABELS = [
        'transfer' => 'Transfer', 'owner_out' => 'Owner took out', 'owner_in' => 'Owner put in', 'opening_balance' => 'Opening balance',
        'count_over' => 'Cash over (count)', 'count_short' => 'Cash short (count)',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['txn_date' => 'date', 'amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'from_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** The day closing that wrote this entry (only for count_over / count_short). */
    public function closing(): HasOne
    {
        return $this->hasOne(DayClosing::class, 'adjustment_transaction_id');
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }

    public function isFromCount(): bool
    {
        return in_array($this->type, self::COUNT_TYPES, true);
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }
}
