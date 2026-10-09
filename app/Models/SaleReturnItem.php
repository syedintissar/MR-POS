<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    public const CONDITIONS = ['resellable' => 'Resellable', 'damaged' => 'Damaged', 'defective' => 'Defective'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_net_price' => 'decimal:4', 'unit_tax' => 'decimal:4', 'net_amount' => 'decimal:2', 'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2', 'unit_cost' => 'decimal:4', 'restock' => 'boolean',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
