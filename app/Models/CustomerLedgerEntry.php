<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a customer's account. Append-only: created by CustomerAccountService, never changed (the database enforces it). */
class CustomerLedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'entry_date' => 'date', 'created_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Customer ledger entries are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new \LogicException('Customer ledger entries are append-only.');
    }

    public static function label(string $type): string
    {
        return [
            'opening_balance' => 'Opening balance',
            'sale' => 'Sale',
            'sale_void' => 'Sale cancelled',
            'service_invoice' => 'Service invoice',
            'service_void' => 'Service invoice cancelled',
            'payment' => 'Payment received',
            'payment_void' => 'Payment cancelled',
            'refund' => 'Refund paid',
            'refund_void' => 'Refund cancelled',
            'sale_return' => 'Goods returned',
            'balance_adjustment' => 'Balance adjustment',
        ][$type] ?? $type;
    }
}
