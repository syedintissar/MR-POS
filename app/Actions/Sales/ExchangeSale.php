<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * One-click exchange: goods come back against an invoice and other goods are sold in their place, as ONE all-or-nothing step. If anything
 * fails (not enough stock for the new items, a refused payment, a missing permission) neither the return nor the new sale exists afterwards.
 *
 * It is the two ordinary actions, `CreateSaleReturn` and `CompleteSale`, run inside one outer transaction, so every rule of each (the value
 * paid after discounts, the return period, the restocking fee, stock, prices, tax, rounding, credit limits, change) applies unchanged:
 *
 *   mode "net" (named customers): the value of the return becomes store credit (after what they still owed on the old invoice) and pays for
 *       the new items; the customer pays the difference, or keeps what is left over as store credit;
 *   mode "refund" (the only mode for the walk-in customer): the returned value is handed back as money and the new items are paid for in
 *       full; the two payments are recorded as they happened (the screen tells the cashier the net amount to hand over or collect).
 *
 * Lock order: customer (named; read before the transaction so the lock is its first read) -> invoice -> its lines -> all products of both
 * halves in one sorted list -> number sequences -> audit. The pre-locks above make the two halves take their locks in the same order as every
 * other operation; a rare deadlock with a walk-in exchange is retried by the outer transaction.
 */
class ExchangeSale
{
    public function __construct(
        private readonly CreateSaleReturn $returns,
        private readonly CompleteSale $sales,
        private readonly CustomerAccountService $accounts,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{sale_item_id: int|string, quantity: string, condition?: string, restock?: bool}>  $returnLines
     * @param  list<array{product_id: int|string, quantity: string}>  $newLines  the replacement items, priced by the server at the normal list price
     * @param  array{mode?: string, refund_method_id?: int|string|null, pay_method_id?: int|string|null, tendered?: string|null, reference_no?: string|null, waive_fee?: bool, exception_reason?: string|null, notes?: string|null}  $opts
     * @return array{return: SaleReturn, sale: Sale, difference: string} difference: what the new items cost less what the return gave (positive = the customer paid more)
     */
    public function execute(Sale $sale, array $returnLines, array $newLines, array $opts, string $reason, string $checkoutKey, User $by): array
    {
        if (! $by->can('sale_returns.create') || ! $by->can('sales.create')) {
            throw new AuthorizationException('An exchange needs both the right to take goods back and the right to sell.');
        }
        $newLines = array_values(array_filter($newLines, fn ($l) => isset($l['product_id'], $l['quantity']) && Decimal::isNumber($l['quantity']) && Decimal::isPositive(Decimal::round((string) $l['quantity'], 3))));
        if ($newLines === []) {
            throw new DomainException('Choose the items the customer takes instead.');
        }

        $customerId = Sale::query()->whereKey($sale->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This invoice no longer exists.');
        }
        $walkInId = Customer::walkIn()->id;   // read before the transaction: the customer lock must be its first read
        $named = (int) $customerId !== $walkInId;
        $mode = $named && ($opts['mode'] ?? 'net') === 'net' ? 'net' : 'refund';

        return DB::transaction(function () use ($sale, $returnLines, $newLines, $opts, $reason, $checkoutKey, $by, $customerId, $named, $mode) {
            if ($named) {
                $this->accounts->lockCustomer((int) $customerId);
            }
            $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();
            $itemIds = array_map(fn ($l) => (int) $l['sale_item_id'], $returnLines);
            $items = SaleItem::query()->where('sale_id', $locked->id)->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get();
            $this->inventory->lockProducts([...$items->pluck('product_id')->all(), ...array_map(fn ($l) => (int) $l['product_id'], $newLines)]);

            // 1. the return
            $return = $this->returns->execute($locked, $returnLines, [
                'remainder' => $mode === 'net' ? 'credit' : 'refund', 'refund_method_id' => $opts['refund_method_id'] ?? null,
                'waive_fee' => ! empty($opts['waive_fee']), 'exception_reason' => $opts['exception_reason'] ?? null,
            ], $reason, $by);

            // 2. what the new items cost, exactly as the till will work it out
            $data = [
                'idempotency_key' => $checkoutKey, 'customer_id' => $named ? (int) $customerId : null,
                'lines' => array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => (string) $l['quantity']], $newLines),
                'notes' => "Exchange for return {$return->return_no}",
            ];
            $newTotal = $this->sales->quote($data, $by);

            // 3. how the new items are paid for
            $payments = [];
            $credit = $mode === 'net' ? (string) $return->store_credit_amount : '0.00';
            $fromCredit = Decimal::cmp($credit, $newTotal) < 0 ? $credit : $newTotal;
            if (Decimal::isPositive($fromCredit)) {
                $payments[] = ['payment_method_id' => PaymentMethod::query()->where('kind', 'customer_credit')->firstOrFail()->id, 'amount' => $fromCredit];
            }
            $rest = Decimal::sub($newTotal, $fromCredit, 2);
            if (Decimal::isPositive($rest)) {
                $method = PaymentMethod::query()->find((int) ($opts['pay_method_id'] ?? 0)) ?? PaymentMethod::query()->where('kind', 'cash')->where('is_active', true)->orderBy('sort_order')->first();
                if (! $method || ! $method->is_active || $method->kind === 'customer_credit') {
                    throw new DomainException('Choose how the customer pays.');
                }
                $given = isset($opts['tendered']) && trim((string) $opts['tendered']) !== '' ? Decimal::round((string) $opts['tendered'], 2) : $rest;
                $payments[] = ['payment_method_id' => $method->id, 'amount' => $given, 'reference_no' => $opts['reference_no'] ?? null];
            }

            // 4. the replacement sale
            $new = $this->sales->execute($data + ['payments' => $payments], $by);

            $return->forceFill(['exchange_sale_id' => $new->id])->save();

            $credited = Decimal::sub((string) $return->total, (string) $return->restocking_fee, 2);
            $difference = Decimal::sub((string) $new->grand_total, $credited, 2);
            $this->audit->log('sale_exchanged', 'sales', "Exchange: {$return->return_no} ({$credited} credited) for {$new->invoice_no} ({$new->grand_total})", $return, new: [
                'invoice' => $locked->invoice_no, 'return' => $return->return_no, 'new_invoice' => $new->invoice_no, 'mode' => $mode,
                'credited' => $credited, 'new_total' => (string) $new->grand_total, 'difference' => $difference, 'reason' => $reason,
            ], user: $by);

            return ['return' => $return->refresh(), 'sale' => $new->refresh(), 'difference' => $difference];
        }, 3);
    }
}
