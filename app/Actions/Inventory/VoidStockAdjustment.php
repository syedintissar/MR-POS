<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a posted adjustment by writing the opposite movements (nothing is deleted or edited).
 * Refused when the stock needed to undo it is no longer there and negative stock is not allowed.
 */
class VoidStockAdjustment
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(StockAdjustment $adjustment, string $reason, User $by): StockAdjustment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the adjustment.');
        }

        return DB::transaction(function () use ($adjustment, $reason, $by) {
            $locked = StockAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->adjustment_no} has already been cancelled.");
            }

            $items = $locked->items()->orderBy('product_id')->get();
            $this->inventory->lockProducts($items->pluck('product_id')->all());

            foreach ($items as $item) {
                $wasIn = Decimal::isPositive($item->quantity);
                $this->inventory->move($item->product_id, Decimal::neg($item->quantity, 3), $wasIn ? 'adjustment_out' : 'adjustment_in', [
                    'unit_cost' => $item->unit_cost,
                    // Undoing stock that came in must also undo its effect on the average cost.
                    'cost_mode' => $wasIn ? InventoryService::COST_MODE_UNWIND : InventoryService::COST_MODE_BLEND,
                    'source' => $locked,
                    'source_line_id' => $item->id,
                    'notes' => "Cancelled {$locked->adjustment_no}: {$reason}",
                    'user' => $by,
                ]);
            }

            $locked->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();

            $this->audit->log('stock_adjustment_voided', 'inventory', "Stock adjustment {$locked->adjustment_no} cancelled: {$reason}", $locked, user: $by);

            return $locked->refresh();
        }, 3);
    }
}
