<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Actions\Inventory\PostStockAdjustment;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Creating, changing, (de)activating and deleting products, with the audit trail and the safety rules. */
class SaveProduct
{
    private const AUDITED_FIELDS = [
        'type', 'name', 'sku', 'barcode', 'category_id', 'brand_id', 'unit_id', 'model', 'description', 'tax_rate',
        'min_stock_level', 'rack_location', 'default_supplier_id', 'warranty_months', 'warranty_terms', 'is_active',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly PostStockAdjustment $adjustments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  already validated
     * @param  array{quantity: string, unit_cost?: string|null}|null  $opening  stock the product starts with
     */
    public function create(array $data, ?array $opening, User $by, bool $audit = true): Product
    {
        return DB::transaction(function () use ($data, $opening, $by, $audit) {
            $data['sku'] = $this->resolveSku($data['sku'] ?? null);
            $data['barcode'] = $this->clean($data['barcode'] ?? null);
            $data['created_by'] = $by->id;
            $data['updated_by'] = $by->id;

            $product = Product::query()->create($data);

            if ($opening && $product->isStockItem() && Decimal::isPositive($opening['quantity'] ?? '0')) {
                $this->adjustments->execute([
                    'reason' => 'opening',
                    'notes' => 'Opening stock entered with the new product',
                    'lines' => [['product_id' => $product->id, 'quantity' => $opening['quantity'], 'unit_cost' => $opening['unit_cost'] ?? null]],
                ], $by);
            }

            if ($audit) {
                $this->audit->log('product_created', 'products', "Product {$product->sku} · {$product->name} created", $product, new: $this->snapshot($product) + [
                    'opening_stock' => $opening['quantity'] ?? null,
                ], user: $by);
            }

            return $product->refresh();
        });
    }

    /** @param  array<string, mixed>  $data  already validated */
    public function update(Product $product, array $data, User $by): Product
    {
        // Prices can only be changed by people who hold products.edit_price: others' values are ignored, not an error.
        if (! $by->can('products.edit_price')) {
            $data = array_diff_key($data, array_flip(Product::PRICE_FIELDS));
        }

        return DB::transaction(function () use ($product, $data, $by) {
            $p = Product::query()->withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('barcode', $data)) {
                $data['barcode'] = $this->clean($data['barcode']);
            }
            if (isset($data['sku'])) {
                $data['sku'] = trim((string) $data['sku']) ?: $p->sku;
            }

            $hasHistory = $p->hasHistory();
            if (isset($data['unit_id']) && (int) $data['unit_id'] !== (int) $p->unit_id && $hasHistory) {
                throw new DomainException('The unit cannot be changed once the product has stock history.');
            }
            if (isset($data['type']) && $data['type'] !== $p->type) {
                if ($hasHistory || ! Decimal::isZero($p->current_stock)) {
                    throw new DomainException('A product that has stock or stock history cannot be switched between stock item and service.');
                }
            }

            $oldAll = $this->snapshot($p) + $this->prices($p);
            $p->fill($data);
            $p->updated_by = $by->id;
            $p->save();
            $p->refresh();
            $newAll = $this->snapshot($p) + $this->prices($p);

            $priceOld = $priceNew = [];
            foreach (Product::PRICE_FIELDS as $f) {
                if ((string) $oldAll[$f] !== (string) $newAll[$f]) {
                    $priceOld[$f] = $oldAll[$f];
                    $priceNew[$f] = $newAll[$f];
                }
            }
            if ($priceNew !== []) {
                $this->audit->log('product_price_changed', 'products', "Price changed for {$p->sku} · {$p->name}", $p, $priceOld, $priceNew, $by);
            }

            $old = $new = [];
            foreach (self::AUDITED_FIELDS as $f) {
                if ((string) $oldAll[$f] !== (string) $newAll[$f]) {
                    $old[$f] = $oldAll[$f];
                    $new[$f] = $newAll[$f];
                }
            }
            if ($new !== []) {
                $this->audit->log('product_updated', 'products', "Product {$p->sku} · {$p->name} updated", $p, $old, $new, $by);
            }

            return $p;
        });
    }

    public function setActive(Product $product, bool $active, User $by): void
    {
        if ($product->is_active === $active) {
            return;
        }
        $product->forceFill(['is_active' => $active, 'updated_by' => $by->id])->save();
        $this->audit->log($active ? 'product_activated' : 'product_deactivated', 'products', "Product {$product->sku} · {$product->name} ".($active ? 'activated' : 'deactivated'), $product, user: $by);
    }

    public function delete(Product $product, User $by): void
    {
        DB::transaction(function () use ($product, $by) {
            $p = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($p->hasHistory()) {
                throw new DomainException('This product has stock history, so it cannot be deleted. Deactivate it instead: it will be hidden from sales but all reports stay correct.');
            }
            $this->audit->log('product_deleted', 'products', "Product {$p->sku} · {$p->name} deleted", $p, $this->snapshot($p), user: $by);
            $p->delete();
        });
    }

    private function resolveSku(?string $sku): string
    {
        $sku = trim((string) $sku);
        if ($sku !== '') {
            return $sku;
        }

        // Auto-suggested SKU from the numbering sequence; skip any number someone already typed in by hand.
        for ($i = 0; $i < 20; $i++) {
            $candidate = $this->numbers->next('product');
            if (! Product::query()->withTrashed()->where('sku', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new DomainException('Could not generate a free SKU. Please type one.');
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $p): array
    {
        $out = [];
        foreach (self::AUDITED_FIELDS as $f) {
            $v = $p->getAttribute($f);
            $out[$f] = is_bool($v) ? (int) $v : $v;
        }

        return $out;
    }

    /** @return array<string, string|null> */
    private function prices(Product $p): array
    {
        return array_combine(Product::PRICE_FIELDS, array_map(fn ($f) => $p->getAttribute($f) === null ? null : (string) $p->getAttribute($f), Product::PRICE_FIELDS));
    }
}
