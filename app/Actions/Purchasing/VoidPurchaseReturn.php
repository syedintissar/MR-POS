<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a purchase return that was made by mistake: the goods come back into stock at the cost they left at, the supplier's account
 * and the bill owe what they owed before, and a refund the supplier paid is cancelled. Nothing is deleted.
 */
class VoidPurchaseReturn
{
    public function __construct(
        private readonly SupplierAccountService $accounts,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(PurchaseReturn $return, string $reason, User $by): PurchaseReturn
    {
        if (! $by->can('purchases.void')) {
            throw new AuthorizationException('You are not allowed to cancel purchase returns.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give a reason for cancelling the return.');
        }

        $supplierId = PurchaseReturn::query()->whereKey($return->getKey())->value('supplier_id');
        if ($supplierId === null) {
            throw new DomainException('This return no longer exists.');
        }

        return DB::transaction(function () use ($return, $reason, $by, $supplierId) {
            $supplier = $this->accounts->lockSupplier((int) $supplierId);
            $locked = PurchaseReturn::query()->whereKey($return->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->return_no} has already been cancelled.");
            }
            $purchase = $locked->purchase_id ? Purchase::query()->whereKey($locked->purchase_id)->lockForUpdate()->firstOrFail() : null;
            $rows = $locked->items()->orderBy('id')->get();
            PurchaseItem::query()->whereIn('id', $rows->pluck('purchase_item_id')->filter())->orderBy('id')->lockForUpdate()->get();

            $this->inventory->lockProducts($rows->pluck('product_id')->all());
            foreach ($rows as $row) {
                $this->inventory->move($row->product_id, (string) $row->quantity, 'purchase_return_void', [
                    'unit_cost' => (string) $row->unit_cost, 'source' => $locked, 'source_line_id' => $row->id, 'notes' => "Return {$locked->return_no} cancelled", 'user' => $by,
                ]);
                if ($row->purchase_item_id) {
                    $item = PurchaseItem::query()->findOrFail($row->purchase_item_id);
                    $item->forceFill(['returned_quantity' => Decimal::nonNegative(Decimal::sub((string) $item->returned_quantity, (string) $row->quantity, 3), 3)])->save();
                }
            }

            $locked->forceFill(['status' => PurchaseReturn::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            if ($purchase !== null) {
                $purchase->forceFill(['returned_amount' => Decimal::nonNegative(Decimal::sub((string) $purchase->returned_amount, (string) $locked->total, 2), 2)])->save();
                $this->accounts->refreshPurchase($purchase);
            }

            $this->accounts->postEntry($supplier, 'purchase_return_void', '0', (string) $locked->total, $locked, "Return {$locked->return_no} cancelled: {$reason}", $by);
            $refund = $locked->refundPayment;
            if ($refund && $refund->status === 'posted') {
                $refund->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => 'Return cancelled'])->save();
                $this->accounts->postEntry($supplier, 'refund_received_void', (string) $refund->amount, '0', $refund, "Refund {$refund->payment_no} cancelled with {$locked->return_no}", $by);
            }

            $this->audit->log('purchase_return_voided', 'purchasing', "Return {$locked->return_no} cancelled: {$reason}", $locked, new: [
                'reason' => $reason, 'total' => (string) $locked->total, 'purchase' => $purchase?->purchase_no,
            ], user: $by);

            return $locked->refresh();
        }, 3);
    }
}
