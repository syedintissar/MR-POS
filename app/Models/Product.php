<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;

    public const TYPE_STOCK = 'stock';

    public const TYPE_SERVICE = 'service';

    /**
     * Cost and stock figures are NOT mass-assignable on purpose: only InventoryService may change them.
     */
    protected $fillable = [
        'type', 'name', 'sku', 'barcode', 'category_id', 'brand_id', 'unit_id', 'model', 'description',
        'sale_price', 'wholesale_price', 'min_sale_price', 'tax_rate', 'min_stock_level', 'rack_location',
        'default_supplier_id', 'warranty_months', 'warranty_terms', 'image_path', 'is_active', 'created_by', 'updated_by',
    ];

    /** Fields that count as a "price change" (audited, and need products.edit_price). */
    public const PRICE_FIELDS = ['sale_price', 'wholesale_price', 'min_sale_price'];

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_purchase_cost' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'sale_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'min_sale_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'current_stock' => 'decimal:3',
            'min_stock_level' => 'decimal:3',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isStockItem(): bool
    {
        return $this->type === self::TYPE_STOCK;
    }

    /** Anything that makes deleting unsafe. Later phases (sales, purchases, jobs) add their tables here. */
    public function hasHistory(): bool
    {
        return $this->movements()->exists();
    }

    /** The level below which this product counts as "low": its own minimum, or the global default. */
    public function lowStockLevel(string $globalDefault): string
    {
        return $this->min_stock_level !== null ? (string) $this->min_stock_level : $globalDefault;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeStockItems(Builder $q): Builder
    {
        return $q->where('type', self::TYPE_STOCK);
    }

    /** Name, SKU, barcode or model contains the term. An exact SKU/barcode match is ranked first. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)
            ->orWhere('barcode', 'like', $like)->orWhere('model', 'like', $like))
            ->orderByRaw('(sku = ? OR barcode = ?) DESC', [$term, $term]);
    }

    /** In stock but at or below the minimum level. */
    public function scopeLowStock(Builder $q, string $globalDefault): Builder
    {
        return $q->stockItems()->where('current_stock', '>', 0)
            ->whereRaw('current_stock <= COALESCE(min_stock_level, ?)', [$globalDefault]);
    }

    public function scopeOutOfStock(Builder $q): Builder
    {
        return $q->stockItems()->where('current_stock', '<=', 0);
    }
}
