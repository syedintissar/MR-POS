<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bank or wallet statement matched against the book. Nothing is mass-assignable: the reconciliation service writes every column. */
class BankReconciliation extends Model
{
    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date', 'opening_balance' => 'decimal:2', 'statement_balance' => 'decimal:2',
            'completed_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }
}
