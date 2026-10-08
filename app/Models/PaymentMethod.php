<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    public const KINDS = [
        'cash' => 'Cash',
        'bank' => 'Bank transfer / cheque',
        'card' => 'Card',
        'wallet' => 'Mobile wallet (Easypaisa, JazzCash)',
        'customer_credit' => 'Customer credit (store credit)',
        'other' => 'Other',
    ];

    protected $fillable = ['name', 'code', 'kind', 'requires_reference', 'is_active', 'is_system', 'sort_order'];

    protected function casts(): array
    {
        return ['requires_reference' => 'boolean', 'is_active' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Methods that can be chosen when paying a supplier (store credit only makes sense for customers). */
    public function scopeForSuppliers(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('kind', '!=', 'customer_credit')->orderBy('sort_order')->orderBy('name');
    }

    /** Methods that can be chosen at the till. Customer credit joins them with named customers (Phase 6). */
    public function scopeForTill(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('kind', '!=', 'customer_credit')->orderBy('sort_order')->orderBy('name');
    }

    public function isUsed(): bool
    {
        return $this->supplierPayments()->exists();
    }
}
