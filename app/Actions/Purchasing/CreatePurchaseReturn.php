<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Exceptions\InsufficientStockException;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sends goods back to a supplier (PRT-01..03), all or nothing.
 *
 *  - against a received purchase: each line returns at most what was received minus what was already returned, and is worth what the
 *    supplier billed for those units (their share of the line after its discount, plus its share of the line's tax; the last units of a
 *    line take whatever is left). The stock leaves at the line's landed cost.
 *  - without a purchase ("free-form"): the supplier, the product, the quantity and the credit the supplier gives per unit are entered;
 *    the stock leaves at the product's average cost.
 *  - what the supplier credits first reduces what we still owe on that bill; whatever is left over (the bill was already paid, or there
 *    is no bill) stays on the supplier's account as credit ("adjust_balance"), or the supplier hands it back as money ("refund",
 *    needs supplier_payments.create).
 *
 * Lock order: supplier -> purchase -> its lines -> products (by id) -> number sequences -> audit.
 */
class CreatePurchaseReturn
{
    public function __construct(
        private readonly SupplierAccountService $accounts,
        private readonly InventoryService $inventory,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id?: int|string|null, purchase_id?: int|string|null, lines: list<array{purchase_item_id?: int|string|null, product_id?: int|string|null, quantity: string, unit_credit?: string|null}>, settlement?: string|null, payment_method_id?: int|string|null, reference_no?: string|null, reason: string}  $data
     */
    public function execute(array $data, User $by): PurchaseReturn
    {
        if (! $by->can('purchase_returns.create')) {
            throw new AuthorizationException('You are not allowed to create purchase returns.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('Please give the reason for the return.');
        }
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => isset($l['quantity']) && Decimal::isNumber($l['quantity']) && Decimal::isPositive(Decimal::round((string) $l['quantity'], 3))));
        if ($lines === []) {
            throw new DomainException('Choose at least one item and a quantity to send back.');
        }
        $settlement = (string) ($data['settlement'] ?? 'adjust_balance');
        if (! in_array($settlement, ['adjust_balance', 'refund'], true)) {
            throw new DomainException('Say whether the supplier credits our account or pays the money back.');
        }

        $purchaseId = ! empty($data['purchase_id']) ? (int) $data['purchase_id'] : null;
        $supplierId = $purchaseId !== null ? Purchase::query()->whereKey($purchaseId)->value('supplier_id') : (! empty($data['supplier_id']) ? (int) $data['supplier_id'] : null);
        if ($purchaseId !== null && $supplierId === null) {
            throw new DomainException('This purchase no longer exists.');
        }
        if ($supplierId === null || ! Supplier::query()->withTrashed()->whereKey($supplierId)->exists()) {
            throw new DomainException('Choose the supplier the goods go back to.');
        }

        return DB::transaction(function () use ($data, $lines, $settlement, $reason, $by, $purchaseId, $supplierId) {
            $supplier = $this->accounts->lockSupplier((int) $supplierId);

            $purchase = null;
            $items = collect();
            if ($purchaseId !== null) {
                $purchase = Purchase::query()->whereKey($purchaseId)->lockForUpdate()->firstOrFail();
                if (! $purchase->isReceived()) {
                    throw new DomainException($purchase->isVoided() ? "{$purchase->label()} has been cancelled: nothing can be sent back against it." : 'Only a received purchase can have goods sent back.');
                }
                $itemIds = array_map(fn ($l) => (int) ($l['purchase_item_id'] ?? 0), $lines);
                if (in_array(0, $itemIds, true)) {
                    throw new DomainException('Every line of a return against a purchase must be one of its items.');
                }
                if (count($itemIds) !== count(array_unique($itemIds))) {
                    throw new DomainException('An item appears twice in the return.');
                }
                $items = PurchaseItem::query()->where('purchase_id', $purchase->id)->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($items->count() !== count($itemIds)) {
                    throw new DomainException('One of the items is not on this purchase.');
                }
                $productIds = $items->pluck('product_id')->all();
            } else {
                $productIds = array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines);
                if (in_array(0, $productIds, true)) {
                    throw new DomainException('Choose the product for every line.');
                }
                if (count($productIds) !== count(array_unique($productIds))) {
                    throw new DomainException('A product appears twice in the return.');
                }
            }
            $products = $this->inventory->lockProducts($productIds);

            $built = [];
            $subtotal = '0.00';
            $tax = '0.00';
            foreach ($lines as $l) {
                $qty = Decimal::round((string) $l['quantity'], 3);
                if ($purchase !== null) {
                    $item = $items[(int) $l['purchase_item_id']];
                    $product = $products[$item->product_id];
                    $remaining = Decimal::sub((string) $item->quantity, (string) $item->returned_quantity, 3);
                    if (Decimal::cmp($qty, $remaining) > 0) {
                        throw new DomainException("{$product->name}: only ".Decimal::trim($remaining).' can still be sent back (received '.Decimal::trim((string) $item->quantity).', already sent back '.Decimal::trim((string) $item->returned_quantity).').');
                    }
                    [$net, $lineTax] = $this->value($item, $qty, $remaining);
                    $cost = (string) ($item->landed_unit_cost ?? $item->unit_cost);
                } else {
                    $item = null;
                    $product = $products[(int) $l['product_id']] ?? null;
                    if ($product === null) {
                        throw new DomainException('One of the products no longer exists.');
                    }
                    $credit = Decimal::round((string) ($l['unit_credit'] ?? '0'), 4);
                    if (Decimal::isNegative($credit)) {
                        throw new DomainException('The credit per unit cannot be negative.');
                    }
                    $net = Decimal::mul($qty, $credit, 2);
                    $lineTax = '0.00';
                    $cost = (string) $product->getRawOriginal('average_cost');
                }
                if ($product->trashed() && $purchase === null) {
                    throw new DomainException("\"{$product->name}\" has been deleted, so it cannot be sent back.");
                }
                if (! $product->isStockItem()) {
                    throw new DomainException("\"{$product->name}\" is a service item and has no stock to send back.");
                }

                $built[] = ['item' => $item, 'product' => $product, 'qty' => $qty, 'net' => $net, 'tax' => $lineTax, 'cost' => $cost];
                $subtotal = Decimal::add($subtotal, $net, 2);
                $tax = Decimal::add($tax, $lineTax, 2);
            }
            $total = Decimal::add($subtotal, $tax, 2);
            if (! Decimal::isPositive($total)) {
                throw new DomainException('The supplier gives no credit for these goods, so there is nothing to record. Enter the credit per unit.');
            }

            // what we still owe on the bill goes down first; the rest is credit with the supplier or money handed back
            $due = $purchase !== null ? Decimal::nonNegative((string) $purchase->due_amount, 2) : '0.00';
            $applied = Decimal::cmp($total, $due) < 0 ? $total : $due;
            $remainder = Decimal::sub($total, $applied, 2);

            $method = null;
            $reference = isset($data['reference_no']) && trim((string) $data['reference_no']) !== '' ? mb_substr(trim((string) $data['reference_no']), 0, 100) : null;
            if ($settlement === 'refund' && Decimal::isPositive($remainder)) {
                if (! $by->can('supplier_payments.create')) {
                    throw new AuthorizationException('You are not allowed to receive money from suppliers. Let the credit stay on their account, or ask a manager.');
                }
                $method = PaymentMethod::query()->find((int) ($data['payment_method_id'] ?? 0));
                if (! $method || ! $method->is_active || $method->kind === 'customer_credit') {
                    throw new DomainException('Choose how the supplier pays the money back.');
                }
                if ($method->requires_reference && $reference === null) {
                    throw new DomainException("{$method->name} refunds need a reference (transaction, cheque or slip number).");
                }
            }

            // the refund (if any) is written first: the database insists that a "refund" return points at its payment
            $returnNo = $this->numbers->next('purchase_return');
            $refund = '0.00';
            $payment = null;
            if ($method !== null) {
                $refund = $remainder;
                $payment = new SupplierPayment;
                $payment->forceFill([
                    'payment_no' => $this->numbers->next('supplier_payment'), 'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(),
                    'payment_method_id' => $method->id, 'type' => 'refund', 'amount' => $refund, 'reference_no' => $reference,
                    'notes' => mb_substr("Refund for return {$returnNo}", 0, 255), 'status' => 'posted', 'created_by' => $by->id,
                ])->save();
            }

            $return = new PurchaseReturn;
            $return->forceFill([
                'return_no' => $returnNo, 'supplier_id' => $supplier->id, 'purchase_id' => $purchase?->id, 'return_date' => now(),
                'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total, 'applied_to_due' => $applied, 'credit_amount' => Decimal::sub($remainder, $refund, 2), 'refund_amount' => $refund, 'settlement' => $payment ? 'refund' : 'adjust_balance', 'supplier_payment_id' => $payment?->id,
                'reason' => mb_substr($reason, 0, 255), 'status' => PurchaseReturn::POSTED, 'created_by' => $by->id,
            ]);
            $return->save();

            foreach ($built as $b) {
                $row = new PurchaseReturnItem;
                $row->forceFill([
                    'purchase_return_id' => $return->id, 'purchase_item_id' => $b['item']?->id, 'product_id' => $b['product']->id, 'quantity' => $b['qty'],
                    'unit_cost' => $b['cost'], 'net_amount' => $b['net'], 'tax_amount' => $b['tax'], 'line_total' => Decimal::add($b['net'], $b['tax'], 2),
                ])->save();

                try {
                    $this->inventory->move($b['product']->id, Decimal::neg($b['qty'], 3), 'purchase_return', [
                        'unit_cost' => $b['cost'], 'source' => $return, 'source_line_id' => $row->id, 'notes' => "Sent back to {$supplier->name}: {$reason}", 'user' => $by,
                    ]);
                } catch (InsufficientStockException $e) {
                    throw new DomainException('Not enough in stock to send back: '.$e->getMessage(), 0, $e);
                }
                if ($b['item'] !== null) {
                    $b['item']->forceFill(['returned_quantity' => Decimal::add((string) $b['item']->returned_quantity, $b['qty'], 3)])->save();
                }
            }

            if ($purchase !== null) {
                $purchase->forceFill(['returned_amount' => Decimal::add((string) $purchase->returned_amount, $total, 2)])->save();
                $this->accounts->refreshPurchase($purchase);
            }
            $this->accounts->postEntry($supplier, 'purchase_return', $total, '0', $return, "Return {$return->return_no}".($purchase ? " against {$purchase->purchase_no}" : '').": {$reason}", $by);

            if ($payment !== null) {
                $this->accounts->postEntry($supplier, 'refund_received', '0', $refund, $payment, "Refund {$payment->payment_no} for return {$return->return_no} · {$method->name}", $by);
            }

            $this->audit->log('purchase_return_created', 'purchasing', "Return {$return->return_no} of {$total} to {$supplier->name}", $return, new: [
                'supplier' => $supplier->name, 'purchase' => $purchase?->purchase_no, 'total' => $total, 'taken_off_the_bill' => $applied, 'refund' => $refund,
                'lines' => array_map(fn ($b) => ['product' => $b['product']->name, 'quantity' => $b['qty'], 'credit' => Decimal::add($b['net'], $b['tax'], 2), 'cost' => $b['cost']], $built),
                'reason' => $reason,
            ], user: $by);

            return $return->refresh()->load('items');
        }, 3);
    }

    /**
     * What a number of units of this purchase line are worth: their share of the line after its discount, and of its tax. Sending back
     * everything that is left of the line takes exactly what is left, so the pieces always add up.
     *
     * @return array{0: string, 1: string} net, tax
     */
    private function value(PurchaseItem $item, string $qty, string $remaining): array
    {
        $netFull = Decimal::sub(Decimal::mul((string) $item->quantity, (string) $item->unit_cost, 2), (string) $item->discount_amount, 2);
        $taxFull = (string) $item->tax_amount;

        if (Decimal::cmp($qty, $remaining) === 0) {
            $given = DB::table('purchase_return_items as ri')->join('purchase_returns as r', 'r.id', '=', 'ri.purchase_return_id')
                ->where('ri.purchase_item_id', $item->id)->where('r.status', 'posted')
                ->selectRaw('COALESCE(SUM(ri.net_amount), 0) AS net, COALESCE(SUM(ri.tax_amount), 0) AS tax')->first();

            return [Decimal::nonNegative(Decimal::sub($netFull, (string) $given->net, 2), 2), Decimal::nonNegative(Decimal::sub($taxFull, (string) $given->tax, 2), 2)];
        }

        $share = fn (string $amount) => Decimal::round(Decimal::div(Decimal::mul($amount, $qty, 8), (string) $item->quantity, 8), 2);

        return [$share($netFull), $share($taxFull)];
    }
}
