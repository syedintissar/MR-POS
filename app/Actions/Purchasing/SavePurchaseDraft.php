<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Purchasing\PurchaseCalculator;
use App\Support\Decimal;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or replaces a purchase DRAFT. A draft has no number, moves no stock and touches no account;
 * only receiving it (ReceivePurchase) does that. A draft can be edited freely or deleted.
 */
class SavePurchaseDraft
{
    public function __construct(
        private readonly PurchaseCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id: int, supplier_invoice_no?: string|null, purchase_date: string, discount_amount?: string|null, tax_amount?: string|null, additional_cost?: string|null, notes?: string|null, lines: list<array{product_id: int, quantity: string, unit_cost: string, discount_amount?: string|null, tax_amount?: string|null}>}  $data
     */
    public function execute(array $data, User $by, ?Purchase $draft = null): Purchase
    {
        $lines = array_values($data['lines'] ?? []);
        if ($lines === []) {
            throw new DomainException('Add at least one product to the purchase.');
        }

        $invoiceNo = isset($data['supplier_invoice_no']) ? trim((string) $data['supplier_invoice_no']) : '';
        $invoiceNo = $invoiceNo === '' ? null : $invoiceNo;

        return DB::transaction(function () use ($data, $lines, $invoiceNo, $by, $draft) {
            $current = null;
            if ($draft) {
                $current = Purchase::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();
                if (! $current->isDraft()) {
                    throw new DomainException("{$current->label()} has already been received and can no longer be edited.");
                }
            }

            $supplier = Supplier::query()->find((int) $data['supplier_id']);
            if (! $supplier) {
                throw new DomainException('Choose a supplier.');
            }
            if (! $supplier->is_active && (! $current || $current->supplier_id !== $supplier->id)) {
                throw new DomainException("Supplier \"{$supplier->name}\" is inactive. Re-activate it before buying from it.");
            }

            $this->assertInvoiceFree($supplier->id, $invoiceNo, $current?->id);

            $products = Product::query()->with('unit')->whereIn('id', array_map(fn ($l) => (int) $l['product_id'], $lines))->get()->keyBy('id');
            $clean = [];
            foreach ($lines as $i => $l) {
                $n = $i + 1;
                $p = $products[(int) $l['product_id']] ?? null;
                if (! $p) {
                    throw new DomainException("Line {$n}: the product is no longer available.");
                }
                if (! $p->isStockItem()) {
                    throw new DomainException("Line {$n}: \"{$p->name}\" is a service item; only stock items can be purchased.");
                }

                $qty = Decimal::round($l['quantity'], 3);
                if (! Decimal::isPositive($qty)) {
                    throw new DomainException("Line {$n}: enter a quantity greater than zero.");
                }
                if (! $p->unit->allows_decimal && ! Decimal::isWhole($qty)) {
                    throw new DomainException("Line {$n}: \"{$p->name}\" is counted in whole {$p->unit->name}s.");
                }
                $cost = Decimal::round($l['unit_cost'], 4);
                $discount = Decimal::round($l['discount_amount'] ?? '0', 2);
                $tax = Decimal::round($l['tax_amount'] ?? '0', 2);
                if (Decimal::isNegative($cost) || Decimal::isNegative($discount) || Decimal::isNegative($tax)) {
                    throw new DomainException("Line {$n}: cost, discount and tax cannot be negative.");
                }

                $clean[] = ['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost, 'discount_amount' => $discount, 'tax_amount' => $tax];
            }

            $invDiscount = Decimal::round($data['discount_amount'] ?? '0', 2);
            $invTax = Decimal::round($data['tax_amount'] ?? '0', 2);
            $additional = Decimal::round($data['additional_cost'] ?? '0', 2);
            if (Decimal::isNegative($invDiscount) || Decimal::isNegative($invTax) || Decimal::isNegative($additional)) {
                throw new DomainException('Discount, tax and extra costs cannot be negative.');
            }

            try {
                $totals = $this->calculator->totals($clean, $invDiscount, $invTax, $additional);
            } catch (InvalidArgumentException $e) {
                throw new DomainException($e->getMessage(), 0, $e);
            }

            $header = [
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => $invoiceNo,
                'purchase_date' => $data['purchase_date'],
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $invDiscount,
                'tax_amount' => $invTax,
                'additional_cost' => $additional,
                'grand_total' => $totals['grand_total'],
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'updated_by' => $by->id,
            ];

            try {
                if ($current) {
                    $current->update($header);
                    $current->items()->delete();
                    $purchase = $current;
                } else {
                    $purchase = Purchase::query()->create($header + ['created_by' => $by->id]);
                }
            } catch (QueryException $e) {
                // Another terminal entered the same supplier invoice a moment ago (the database unique key caught it).
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new DomainException("Supplier invoice \"{$invoiceNo}\" has already been entered for {$supplier->name}.", 0, $e);
                }
                throw $e;
            }

            foreach ($clean as $i => $l) {
                PurchaseItem::query()->create($l + ['purchase_id' => $purchase->id, 'line_total' => $totals['lines'][$i]]);
            }

            $this->audit->log($current ? 'purchase_draft_updated' : 'purchase_draft_created', 'purchasing',
                ($current ? 'Updated' : 'Created')." purchase draft #{$purchase->id} for {$supplier->name}: ".count($clean).' line(s), total '.$totals['grand_total'],
                $purchase, new: ['supplier' => $supplier->name, 'invoice' => $invoiceNo, 'lines' => count($clean), 'grand_total' => $totals['grand_total']], user: $by);

            return $purchase->refresh()->load('items.product.unit', 'supplier');
        }, 3);
    }

    private function assertInvoiceFree(int $supplierId, ?string $invoiceNo, ?int $exceptId): void
    {
        if ($invoiceNo === null) {
            return;
        }

        $clash = Purchase::query()->where('supplier_id', $supplierId)->where('supplier_invoice_no', $invoiceNo)
            ->where('status', '!=', Purchase::VOIDED)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->first();

        if ($clash) {
            throw new DomainException("Supplier invoice \"{$invoiceNo}\" has already been entered for this supplier ({$clash->label()}).");
        }
    }
}
