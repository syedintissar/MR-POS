<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money the shop spent on running costs. Nothing is mass-assignable: the expense actions write every column deliberately. */
class Expense extends Model
{
    public const POSTED = 'posted';

    public const VOIDED = 'voided';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
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

    /** EXP-03: an expense can be edited only on the day it was entered. */
    public function isEditable(): bool
    {
        return ! $this->isVoided() && $this->created_at !== null && $this->created_at->isToday();
    }
}
