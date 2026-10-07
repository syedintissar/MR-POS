<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Exceptions\InsufficientStockException;
use App\Models\Purchase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a RECEIVED purchase by writing the opposite entries (nothing is deleted or edited):
 *  - the goods leave stock again, and the average cost is unwound
 *  - the supplier's account is credited back with the bill
 *  - money already paid against the bill stays paid; it simply becomes an advance with that supplier
 *
 * Refused (and nothing changed) when part of the goods has already been sold or used up, or goods were already returned.
 */
class VoidPurchase
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SupplierAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Purchase $purchase, string $reason, User $by): Purchase
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the purchase.');
        }

        $supplierId = Purchase::query()->whereKey($purchase->getKey())->value('supplier_id');
        if ($supplierId === null) {
            throw new DomainException('This purchase no longer exists.');
        }

        return DB::transaction(function () use ($purchase, $supplierId, $reason, $by) {
            $supplier = $this->accounts->lockSupplier((int) $supplierId);
            $locked = Purchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isReceived()) {
                throw new DomainException($locked->isVoided() ? "{$locked->label()} has already been cancelled." : 'Only a received purchase can be cancelled. Delete the draft instead.');
            }

            $items = $locked->items()->orderBy('product_id')->orderBy('id')->get();
            if (Decimal::isPositive((string) $locked->returned_amount) || $items->contains(fn ($i) => Decimal::isPositive((string) $i->returned_quantity))) {
                throw new DomainException("{$locked->label()} has goods returned to the supplier, so it cannot be cancelled. Cancel the return first.");
            }

            $this->inventory->lockProducts($items->pluck('product_id')->all());

            $paid = (string) $locked->paid_amount;
            foreach ($items as $item) {
                try {
                    $this->inventory->move($item->product_id, Decimal::neg((string) $item->quantity, 3), 'purchase_void', [
                        'unit_cost' => $item->landed_unit_cost,
                        'source' => $locked,
                        'source_line_id' => $item->id,
                        'notes' => "Cancelled {$locked->purchase_no}: {$reason}",
                        'user' => $by,
                    ]);
                } catch (InsufficientStockException $e) {
                    throw new DomainException("{$locked->label()} cannot be cancelled: part of those goods is already gone. ".$e->getMessage(), 0, $e);
                }
            }

            $locked->forceFill([
                'status' => Purchase::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id,
                'void_reason' => mb_substr($reason, 0, 255), 'updated_by' => $by->id,
            ])->save();

            if (Decimal::isPositive((string) $locked->grand_total)) {
                $this->accounts->postEntry($supplier, 'purchase_void', (string) $locked->grand_total, '0', $locked,
                    "Purchase {$locked->purchase_no} cancelled: {$reason}", $by);
            }
            $this->accounts->refreshPurchase($locked);

            $this->audit->log('purchase_voided', 'purchasing', "Purchase {$locked->purchase_no} from {$supplier->name} cancelled: {$reason}", $locked, new: [
                'reason' => $reason, 'grand_total' => (string) $locked->grand_total, 'paid_before_cancel_now_advance' => $paid,
            ], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
