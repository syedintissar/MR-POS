<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_id', 'product_id', 'quantity', 'unit_cost', 'discount_amount', 'tax_amount', 'line_total', 'landed_unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2', 'landed_unit_cost' => 'decimal:4', 'returned_quantity' => 'decimal:3',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
