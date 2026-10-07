<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseCalculator;
use App\Services\Purchasing\SupplierAccountService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Receives a purchase: the goods come into stock, the average cost moves, the supplier is owed the bill,
 * and (optionally) the part paid on the spot is recorded - all in ONE database transaction. If anything fails
 * (a missing reference number, a deleted product ...) nothing at all is saved and the draft stays a draft.
 */
class ReceivePurchase
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SupplierAccountService $accounts,
        private readonly PurchaseCalculator $calculator,
        private readonly DocumentNumberService $numbers,
        private readonly PostSupplierPayment $pay,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{payment_method_id: int, amount: string, reference_no?: string|null, notes?: string|null}|null  $payment  money paid now
     */
    public function execute(Purchase $purchase, User $by, ?array $payment = null): Purchase
    {
        $supplierId = Purchase::query()->whereKey($purchase->getKey())->value('supplier_id');
        if ($supplierId === null) {
            throw new DomainException('This purchase no longer exists.');
        }

        return DB::transaction(function () use ($purchase, $supplierId, $by, $payment) {
            // Fixed lock order: supplier -> purchase -> products -> number sequence.
            $supplier = $this->accounts->lockSupplier((int) $supplierId);
            $locked = Purchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->supplier_id !== $supplier->id) {
                throw new DomainException('The supplier of this purchase was changed a moment ago. Open it again and check it.');
            }
            if (! $locked->isDraft()) {
                throw new DomainException("{$locked->label()} has already been ".($locked->isVoided() ? 'cancelled.' : 'received.'));
            }

            $items = $locked->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('A purchase without products cannot be received.');
            }

            $lines = $items->map(fn ($i) => [
                'quantity' => (string) $i->quantity, 'unit_cost' => (string) $i->unit_cost,
                'discount_amount' => (string) $i->discount_amount, 'tax_amount' => (string) $i->tax_amount,
            ])->all();

            // Safety net: the stored totals must still be what the lines add up to.
            $totals = $this->calculator->totals($lines, (string) $locked->discount_amount, (string) $locked->tax_amount, (string) $locked->additional_cost);
            if (Decimal::cmp($totals['grand_total'], (string) $locked->grand_total) !== 0) {
                throw new DomainException('The totals of this draft are out of date. Open it, check it and save it again.');
            }

            $landed = $this->calculator->landedUnitCosts(
                $lines, (string) $locked->discount_amount, (string) $locked->tax_amount, (string) $locked->additional_cost,
                $this->settings->bool('tax.purchase_tax_in_cost'),
            );

            $products = $this->inventory->lockProducts($items->pluck('product_id')->all());
            foreach ($products as $p) {
                if ($p->trashed()) {
                    throw new DomainException("\"{$p->name}\" has been deleted since this draft was made. Remove it from the draft first.");
                }
            }

            $number = $this->numbers->next('purchase');
            $locked->forceFill(['status' => Purchase::RECEIVED, 'purchase_no' => $number, 'received_at' => now(), 'received_by' => $by->id, 'updated_by' => $by->id])->save();

            // Product-id order matches the lock order above, whatever order the lines were typed in.
            $summary = [];
            foreach ($items->sortBy([['product_id', 'asc'], ['id', 'asc']]) as $key => $item) {
                $unitCost = $landed[$key];   // sortBy keeps the original positions, which is what $landed is indexed by
                $movement = $this->inventory->move($products[$item->product_id], (string) $item->quantity, 'purchase', [
                    'unit_cost' => $unitCost,
                    'source' => $locked,
                    'source_line_id' => $item->id,
                    'notes' => "{$number} from {$supplier->name}".($locked->supplier_invoice_no ? " · invoice {$locked->supplier_invoice_no}" : ''),
                    'user' => $by,
                ]);
                $item->update(['landed_unit_cost' => $unitCost]);

                $summary[] = [
                    'sku' => $products[$item->product_id]->sku, 'qty' => Decimal::trim((string) $item->quantity), 'cost' => $unitCost,
                    'avg_after' => Decimal::trim($movement->avg_cost_after),
                ];
            }

            // A bill of nothing (free samples) still adds stock but owes nothing, so it has no ledger line.
            if (Decimal::isPositive((string) $locked->grand_total)) {
                $this->accounts->postEntry($supplier, 'purchase', '0', (string) $locked->grand_total, $locked,
                    "Purchase {$number}".($locked->supplier_invoice_no ? " · invoice {$locked->supplier_invoice_no}" : ''), $by, $locked->purchase_date->toDateString());
            }
            $this->accounts->refreshPurchase($locked);

            $paidNow = '0.00';
            if ($payment !== null && Decimal::isPositive(Decimal::round($payment['amount'] ?? '0', 2))) {
                $paidNow = Decimal::round($payment['amount'], 2);
                if (Decimal::cmp($paidNow, (string) $locked->grand_total) > 0) {
                    throw new DomainException("The amount paid now ({$paidNow}) is more than the purchase total ({$locked->grand_total}). Use Supplier payments for advances.");
                }
            }

            if (Decimal::isPositive($paidNow)) {
                $this->pay->execute([
                    'supplier_id' => $supplier->id,
                    'payment_date' => now()->toDateString(),
                    'payment_method_id' => (int) $payment['payment_method_id'],
                    'amount' => $paidNow,
                    'reference_no' => $payment['reference_no'] ?? null,
                    'notes' => $payment['notes'] ?? "Paid on receiving {$number}",
                    'allocation' => 'manual',
                    'allocations' => [$locked->id => $paidNow],
                ], $by);
            }

            // The audit write comes last on purpose: every operation takes its locks in the same order (supplier, purchases, products,
            // number sequences, audit trail), which is what keeps two terminals from ever waiting on each other in a circle.
            $this->audit->log('purchase_received', 'purchasing', "Purchase {$number} received from {$supplier->name}: ".count($summary)." line(s), total {$locked->grand_total}", $locked, new: [
                'supplier' => $supplier->name, 'invoice' => $locked->supplier_invoice_no, 'grand_total' => (string) $locked->grand_total,
                'paid_now' => $paidNow, 'lines' => array_slice($summary, 0, 100),
            ], user: $by);

            // the payment above updated this bill through its own instance, so read it back as the database now holds it
            return $locked->refresh()->load('items.product.unit', 'supplier');
        }, 3);
    }
}
