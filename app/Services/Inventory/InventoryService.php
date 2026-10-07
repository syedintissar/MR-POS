<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * THE ONLY code allowed to change products.current_stock, average_cost and last_purchase_cost.
 *
 * Every change writes a row to the append-only stock ledger in the same database transaction, under a row
 * lock on the product. Two cashiers selling the last unit at the same moment are therefore serialised:
 * the second one waits, sees zero, and is refused cleanly.
 */
class InventoryService
{
    /** movement type => direction (+1 adds stock, -1 removes it) */
    public const DIRECTION = [
        'opening' => 1, 'purchase' => 1, 'sale_return' => 1, 'sale_void' => 1, 'service_return' => 1, 'adjustment_in' => 1,
        'purchase_return' => -1, 'purchase_void' => -1, 'sale' => -1, 'service_consumption' => -1, 'adjustment_out' => -1,
    ];

    /** How a movement affects the average cost. */
    public const COST_MODE_BLEND = 'blend';   // stock comes in at a cost: average moves toward it

    public const COST_MODE_UNWIND = 'unwind'; // stock goes back out at the cost it came in

    public const COST_MODE_ISSUE = 'issue';   // stock leaves at the current average: average unchanged

    private const DEFAULT_MODE = [
        'opening' => self::COST_MODE_BLEND, 'purchase' => self::COST_MODE_BLEND, 'sale_return' => self::COST_MODE_BLEND,
        'sale_void' => self::COST_MODE_BLEND, 'service_return' => self::COST_MODE_BLEND, 'adjustment_in' => self::COST_MODE_BLEND,
        'purchase_return' => self::COST_MODE_UNWIND, 'purchase_void' => self::COST_MODE_UNWIND,
        'sale' => self::COST_MODE_ISSUE, 'service_consumption' => self::COST_MODE_ISSUE, 'adjustment_out' => self::COST_MODE_ISSUE,
    ];

    /** Types whose cost becomes the product's "last purchase cost". */
    private const SETS_LAST_PURCHASE_COST = ['opening', 'purchase'];

    public function __construct(
        private readonly CostingService $costing,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Lock several products in a fixed order (by id) so documents with many lines can never deadlock each other.
     * Call this first inside the posting transaction, then call move() for each line.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Product> keyed by id
     */
    public function lockProducts(array $ids): Collection
    {
        $this->assertInTransaction();

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return Product::query()->withTrashed()->with('unit')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * @param  string  $quantity  signed: positive adds stock, negative removes it
     * @param  array{unit_cost?: string|null, cost_mode?: string, source?: Model|null, source_line_id?: int|null, notes?: string|null, user?: User|null}  $options
     *
     * @throws InsufficientStockException when stock would drop below zero and the policy forbids it
     */
    public function move(int|Product $product, string $quantity, string $type, array $options = []): StockMovement
    {
        $this->assertInTransaction();

        if (! isset(self::DIRECTION[$type])) {
            throw new InvalidArgumentException("Unknown stock movement type [{$type}].");
        }

        $quantity = Decimal::round($quantity, 3);
        if (Decimal::isZero($quantity)) {
            throw new InvalidArgumentException('A stock movement needs a quantity other than zero.');
        }
        if ((Decimal::isPositive($quantity) ? 1 : -1) !== self::DIRECTION[$type]) {
            throw new InvalidArgumentException("A [{$type}] movement must be ".(self::DIRECTION[$type] === 1 ? 'positive' : 'negative').'.');
        }

        // Always (re-)read the row under lock: another cashier may have changed it a moment ago.
        $id = $product instanceof Product ? $product->getKey() : $product;
        $p = Product::query()->withTrashed()->with('unit')->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! $p->isStockItem()) {
            throw new LogicException("\"{$p->name}\" is a service item and has no stock.");
        }
        if (! $p->unit->allows_decimal && ! Decimal::isWhole($quantity)) {
            throw new InvalidArgumentException("\"{$p->name}\" is counted in whole {$p->unit->name}s: {$quantity} is not allowed.");
        }

        $before = Decimal::round((string) $p->getRawOriginal('current_stock'), 3);
        $after = Decimal::add($before, $quantity, 3);

        if (Decimal::isNegative($quantity) && Decimal::isNegative($after) && ! $this->settings->bool('inventory.allow_negative_stock')) {
            throw new InsufficientStockException($p->name, Decimal::trim($before), Decimal::trim(Decimal::abs($quantity, 3)), $p->unit->short_name);
        }

        $avg = Decimal::round((string) $p->getRawOriginal('average_cost'), 4);
        $unitCost = isset($options['unit_cost']) && $options['unit_cost'] !== null && $options['unit_cost'] !== ''
            ? Decimal::round($options['unit_cost'], 4)
            : $avg;
        if (Decimal::isNegative($unitCost)) {
            throw new InvalidArgumentException('A unit cost cannot be negative.');
        }

        $mode = $options['cost_mode'] ?? self::DEFAULT_MODE[$type];
        $newAvg = match ($mode) {
            self::COST_MODE_BLEND => $this->costing->blend($before, $avg, $quantity, $unitCost),
            self::COST_MODE_UNWIND => $this->costing->unwind($before, $avg, Decimal::abs($quantity, 3), $unitCost),
            default => $avg,
        };

        $source = $options['source'] ?? null;
        $movement = new StockMovement;
        $movement->forceFill([
            'product_id' => $p->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'qty_before' => $before,
            'qty_after' => $after,
            'unit_cost' => $unitCost,
            'avg_cost_after' => $newAvg,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'source_line_id' => $options['source_line_id'] ?? null,
            'notes' => isset($options['notes']) ? mb_substr((string) $options['notes'], 0, 255) : null,
            'user_id' => ($options['user'] ?? auth()->user())?->id,
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ])->save();

        $changes = ['current_stock' => $after, 'average_cost' => $newAvg];
        if (in_array($type, self::SETS_LAST_PURCHASE_COST, true)) {
            $changes['last_purchase_cost'] = $unitCost;
        }
        $p->forceFill($changes)->saveQuietly();

        if ($product instanceof Product) {
            $product->forceFill($changes)->syncOriginal();
        }

        return $movement;
    }

    public function allowNegativeStock(): bool
    {
        return $this->settings->bool('inventory.allow_negative_stock');
    }

    /**
     * Recompute stock from the ledger and report products whose cache disagrees (never fixes anything).
     *
     * @return list<array{product_id:int,name:string,cached:string,ledger:string}>
     */
    public function findDrift(): array
    {
        $rows = DB::select(
            'SELECT p.id, p.name, p.current_stock AS cached, COALESCE(SUM(m.quantity), 0) AS ledger,
                    (SELECT m2.qty_after FROM stock_movements m2 WHERE m2.product_id = p.id ORDER BY m2.id DESC LIMIT 1) AS last_after
               FROM products p LEFT JOIN stock_movements m ON m.product_id = p.id
              WHERE p.type = ?
              GROUP BY p.id, p.name, p.current_stock
             HAVING ABS(cached - ledger) > 0.0005 OR (last_after IS NOT NULL AND ABS(last_after - cached) > 0.0005)',
            [Product::TYPE_STOCK],
        );

        return array_map(fn ($r) => ['product_id' => (int) $r->id, 'name' => $r->name, 'cached' => (string) $r->cached, 'ledger' => (string) $r->ledger], $rows);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('InventoryService must be used inside a database transaction.');
        }
    }
}
