<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a supplier's account. Append-only: created by SupplierAccountService, never changed (the database enforces it). */
class SupplierLedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'entry_date' => 'date', 'created_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Supplier ledger entries are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new \LogicException('Supplier ledger entries are append-only.');
    }

    public static function label(string $type): string
    {
        return [
            'opening_balance' => 'Opening balance',
            'purchase' => 'Purchase',
            'purchase_void' => 'Purchase cancelled',
            'payment' => 'Payment made',
            'payment_void' => 'Payment cancelled',
            'purchase_return' => 'Goods returned',
            'refund_received' => 'Refund received',
            'purchase_return_void' => 'Return cancelled',
            'refund_received_void' => 'Refund cancelled',
            'balance_adjustment' => 'Balance adjustment',
        ][$type] ?? $type;
    }
}
