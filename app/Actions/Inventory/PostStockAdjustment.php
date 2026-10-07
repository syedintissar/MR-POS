<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts a stock adjustment document: one or more products with a signed quantity (+ adds, - removes) and a reason.
 * All-or-nothing: if any line fails (for example it would make stock negative) nothing is saved.
 */
class PostStockAdjustment
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{adjustment_date?: string, reason: string, notes?: string|null, lines: list<array{product_id: int, quantity: string, unit_cost?: string|null, notes?: string|null}>}  $data
     */
    public function execute(array $data, User $by): StockAdjustment
    {
        $reason = $data['reason'];
        if (! array_key_exists($reason, StockAdjustment::REASONS)) {
            throw new InvalidArgumentException('Unknown adjustment reason.');
        }

        $lines = array_values($data['lines'] ?? []);
        if ($lines === []) {
            throw new InvalidArgumentException('An adjustment needs at least one product.');
        }

        $ids = array_map(fn ($l) => (int) $l['product_id'], $lines);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Each product can appear only once in an adjustment.');
        }

        foreach ($lines as $l) {
            $q = Decimal::of($l['quantity']);
            if (Decimal::isZero($q)) {
                throw new InvalidArgumentException('Every line needs a quantity other than zero.');
            }
            if ($reason === 'opening' && Decimal::isNegative($q)) {
                throw new InvalidArgumentException('Opening stock can only add stock.');
            }
        }

        // Retry once or twice if the database detects a deadlock with another terminal.
        return DB::transaction(function () use ($data, $lines, $ids, $reason, $by) {
            $products = $this->inventory->lockProducts($ids);

            foreach ($products as $p) {
                if (! $p->isStockItem()) {
                    throw new InvalidArgumentException("\"{$p->name}\" is a service item and has no stock to adjust.");
                }
            }

            $adjustment = StockAdjustment::query()->create([
                'adjustment_no' => $this->numbers->next('stock_adjustment'),
                'adjustment_date' => $data['adjustment_date'] ?? now()->toDateString(),
                'reason' => $reason,
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'created_by' => $by->id,
            ]);

            // Process in product-id order, matching the lock order.
            usort($lines, fn ($a, $b) => (int) $a['product_id'] <=> (int) $b['product_id']);

            $summary = [];
            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $products[(int) $line['product_id']];
                $qty = Decimal::round($line['quantity'], 3);
                $in = Decimal::isPositive($qty);
                $type = $in ? ($reason === 'opening' ? 'opening' : 'adjustment_in') : 'adjustment_out';

                $item = $adjustment->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_cost' => 0,
                    'notes' => $line['notes'] ?? null,
                ]);

                $movement = $this->inventory->move($product, $qty, $type, [
                    'unit_cost' => $in ? ($line['unit_cost'] ?? null) : null,
                    'source' => $adjustment,
                    'source_line_id' => $item->id,
                    'notes' => $adjustment->adjustment_no.' · '.$adjustment->reasonLabel(),
                    'user' => $by,
                ]);

                $item->update(['unit_cost' => $movement->unit_cost]);
                $summary[] = ['sku' => $product->sku, 'qty' => Decimal::trim($qty), 'from' => Decimal::trim($movement->qty_before), 'to' => Decimal::trim($movement->qty_after)];
            }

            $this->audit->log('stock_adjusted', 'inventory', "Stock adjustment {$adjustment->adjustment_no} ({$adjustment->reasonLabel()}), ".count($lines).' product(s)', $adjustment, new: [
                'reason' => $reason, 'lines_total' => count($summary), 'lines' => array_slice($summary, 0, 100),
            ], user: $by);

            return $adjustment->load('items.product');
        }, 3);
    }
}
