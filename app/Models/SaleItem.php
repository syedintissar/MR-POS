<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'list_price' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'invoice_discount_share' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'net_amount' => 'decimal:2',
            'line_total' => 'decimal:2', 'unit_cost' => 'decimal:4', 'returned_quantity' => 'decimal:3', 'warranty_until' => 'date',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
