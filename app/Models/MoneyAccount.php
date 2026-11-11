<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place where the shop's money is: the cash drawer, a bank account, a mobile wallet.
 * It has no balance column on purpose: the balance is added up from the documents (see MoneyAccountService).
 */
class MoneyAccount extends Model
{
    public const KINDS = [
        'cash' => 'Cash (a drawer or a safe)',
        'bank' => 'Bank account',
        'wallet' => 'Mobile wallet (Easypaisa, JazzCash)',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public function methods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function isCash(): bool
    {
        return $this->kind === 'cash';
    }

    public function kindLabel(): string
    {
        return ['cash' => 'Cash', 'bank' => 'Bank', 'wallet' => 'Wallet'][$this->kind] ?? $this->kind;
    }
}
