<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of the stock ledger. Append-only: created by InventoryService, never changed (the database enforces it). */
class StockMovement extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'qty_before' => 'decimal:3',
            'qty_after' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'avg_cost_after' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Stock movements are append-only.');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new \LogicException('Stock movements are append-only.');
    }

    /** Plain-language label for the movement type. */
    public static function label(string $type): string
    {
        return [
            'opening' => 'Opening stock',
            'purchase' => 'Purchase received',
            'purchase_return' => 'Returned to supplier',
            'purchase_void' => 'Purchase cancelled',
            'sale' => 'Sale',
            'sale_return' => 'Customer return',
            'sale_void' => 'Sale cancelled',
            'service_consumption' => 'Used in repair',
            'service_return' => 'Returned from repair',
            'adjustment_in' => 'Adjustment (added)',
            'adjustment_out' => 'Adjustment (removed)',
        ][$type] ?? $type;
    }
}
