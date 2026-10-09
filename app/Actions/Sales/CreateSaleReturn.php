<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\DocumentNumberService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleAccountService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes goods back against an invoice (SRT-01..06), all or nothing.
 *
 *  - quantity per line is at most what was sold minus what was already returned
 *  - the value of a line is what the customer actually paid for those units: their share of the line's net amount and tax, after both
 *    discounts, so nobody is ever given back more than they paid; returning the rest of a line takes whatever is left (no lost cents)
 *  - only lines marked "restock" put units back in stock, at the cost on the invoice line; the others are a write-off (SRT-06)
 *  - settlement order: 1. reduce what the customer still owes on the invoice; 2. the rest is handed back as money (`sale_returns.refund`)
 *    or left on the customer's account as store credit (named customers; setting `sales.store_credit_enabled`). The walk-in customer
 *    has no account, so for them it is always money back.
 *
 * Lock order: customer (named; read before the transaction) -> invoice -> its lines -> products -> number sequences -> audit.
 */
class CreateSaleReturn
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly SaleAccountService $sales,
        private readonly InventoryService $inventory,
        private readonly DocumentNumberService $numbers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{sale_item_id: int|string, quantity: string, condition?: string, restock?: bool|string|int}>  $lines
     * @param  array{remainder?: string, refund_method_id?: int|string|null}  $settle  remainder: 'refund' | 'credit'
     */
    public function execute(Sale $sale, array $lines, array $settle, string $reason, User $by): SaleReturn
    {
        if (! $by->can('sale_returns.create')) {
            throw new AuthorizationException('You are not allowed to create sales returns.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Please give the reason for the return.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => isset($l['quantity']) && Decimal::isPositive(Decimal::round((string) $l['quantity'], 3))));
        if ($lines === []) {
            throw new DomainException('Choose at least one item and a quantity to return.');
        }

        $customerId = Sale::query()->whereKey($sale->getKey())->value('customer_id');
        if ($customerId === null) {
            throw new DomainException('This invoice no longer exists.');
        }
        $walkInId = Customer::walkIn()->id;   // read before the transaction: the customer lock must be its first read

        return DB::transaction(function () use ($sale, $lines, $settle, $reason, $by, $customerId, $walkInId) {
            $customer = (int) $customerId === $walkInId ? Customer::query()->findOrFail($walkInId) : $this->accounts->lockCustomer((int) $customerId);
            $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new DomainException("{$locked->invoice_no} has been cancelled: nothing can be returned against it.");
            }

            $ids = array_map(fn ($l) => (int) $l['sale_item_id'], $lines);
            if (count($ids) !== count(array_unique($ids))) {
                throw new DomainException('An item appears twice in the return.');
            }
            $items = SaleItem::query()->where('sale_id', $locked->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== count($ids)) {
                throw new DomainException('One of the items is not on this invoice.');
            }

            // which lines go back on the shelf: the flag the cashier set, by default every resellable line
            $wantRestock = [];
            foreach ($lines as $l) {
                $wantRestock[(int) $l['sale_item_id']] = $this->truthy($l['restock'] ?? (($l['condition'] ?? 'resellable') === 'resellable'));
            }
            // products of restocked lines are locked in id order, like every other stock operation
            $restockProducts = [];
            foreach ($wantRestock as $itemId => $want) {
                if ($want) {
                    $restockProducts[] = $items[$itemId]->product_id;
                }
            }
            $products = $restockProducts !== [] ? $this->inventory->lockProducts($restockProducts) : collect();

            $built = [];
            $net = '0.00';
            $tax = '0.00';
            foreach ($lines as $l) {
                $item = $items[(int) $l['sale_item_id']];
                $qty = Decimal::round((string) $l['quantity'], 3);
                $remaining = Decimal::sub((string) $item->quantity, (string) $item->returned_quantity, 3);
                if (Decimal::cmp($qty, $remaining) > 0) {
                    throw new DomainException("{$item->product_name}: only ".Decimal::trim($remaining).' can still be returned (sold '.Decimal::trim((string) $item->quantity).', already returned '.Decimal::trim((string) $item->returned_quantity).').');
                }

                $condition = (string) ($l['condition'] ?? 'resellable');
                if (! array_key_exists($condition, SaleReturnItem::CONDITIONS)) {
                    throw new DomainException('Choose the condition of each returned item.');
                }
                $product = $products[$item->product_id] ?? null;
                $restock = $wantRestock[$item->id] && $product !== null && $product->isStockItem();   // a service item has no stock to put back

                [$lineNet, $lineTax] = $this->value($item, $qty, $remaining);
                $built[] = ['item' => $item, 'qty' => $qty, 'condition' => $condition, 'restock' => $restock, 'net' => $lineNet, 'tax' => $lineTax];
                $net = Decimal::add($net, $lineNet, 2);
                $tax = Decimal::add($tax, $lineTax, 2);
            }
            $total = Decimal::add($net, $tax, 2);
            if (! Decimal::isPositive($total)) {
                throw new DomainException('These items were given away free, so there is nothing to return money for.');
            }

            // 1. what the customer still owes on the invoice is reduced first (named customers only: the walk-in customer owes nothing)
            $due = $customer->is_walk_in ? '0.00' : Decimal::nonNegative((string) $locked->due_amount, 2);
            $applied = Decimal::cmp($total, $due) < 0 ? $total : $due;
            $remainder = Decimal::sub($total, $applied, 2);

            // 2. the rest: money back, or store credit
            $mode = (string) ($settle['remainder'] ?? '');
            $refund = '0.00';
            $credit = '0.00';
            $method = null;
            if (Decimal::isPositive($remainder)) {
                if ($customer->is_walk_in) {
                    $mode = 'refund';
                } elseif ($mode === '') {
                    throw new DomainException('Say what happens to the '.Decimal::trim($remainder).' that is not owed on the invoice: money back or store credit.');
                }
                if ($mode === 'refund') {
                    if (! $by->can('sale_returns.refund')) {
                        throw new AuthorizationException('You are not allowed to pay refunds. Choose store credit, or ask a manager.');
                    }
                    $method = PaymentMethod::query()->find((int) ($settle['refund_method_id'] ?? 0)) ?? PaymentMethod::query()->where('kind', 'cash')->where('is_active', true)->orderBy('sort_order')->first();
                    if (! $method || ! $method->is_active || $method->kind === 'customer_credit') {
                        throw new DomainException('Choose how the money is handed back.');
                    }
                    $refund = $remainder;
                } elseif ($mode === 'credit') {
                    if (! $this->settings->bool('sales.store_credit_enabled')) {
                        throw new DomainException('Store credit is switched off in Settings: hand the money back instead.');
                    }
                    $credit = $remainder;
                } else {
                    throw new DomainException('Say whether the customer gets money back or store credit.');
                }
            }

            $return = new SaleReturn;
            $return->forceFill([
                'return_no' => $this->numbers->next('sale_return'), 'sale_id' => $locked->id, 'customer_id' => $customer->id, 'return_date' => now(),
                'net_total' => $net, 'tax_total' => $tax, 'total' => $total, 'applied_to_due' => $applied, 'refund_amount' => $refund, 'store_credit_amount' => $credit,
                'reason' => mb_substr($reason, 0, 255), 'status' => SaleReturn::POSTED, 'created_by' => $by->id,
            ])->save();

            foreach ($built as $b) {
                /** @var SaleItem $item */
                $item = $b['item'];
                $row = new SaleReturnItem;
                $row->forceFill([
                    'sale_return_id' => $return->id, 'sale_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $b['qty'],
                    'unit_net_price' => Decimal::div($b['net'], $b['qty'], 4), 'unit_tax' => Decimal::div($b['tax'], $b['qty'], 4), 'net_amount' => $b['net'], 'tax_amount' => $b['tax'],
                    'line_total' => Decimal::add($b['net'], $b['tax'], 2), 'unit_cost' => (string) $item->unit_cost, 'item_condition' => $b['condition'], 'restock' => $b['restock'],
                ])->save();

                $item->forceFill(['returned_quantity' => Decimal::add((string) $item->returned_quantity, $b['qty'], 3)])->save();

                if ($b['restock']) {
                    $this->inventory->move($item->product_id, $b['qty'], 'sale_return', [
                        'unit_cost' => (string) $item->unit_cost, 'source' => $return, 'source_line_id' => $row->id,
                        'notes' => "Returned against {$locked->invoice_no}", 'user' => $by,
                    ]);
                }
            }

            $locked->forceFill(['returned_amount' => Decimal::add((string) $locked->returned_amount, $total, 2)])->save();
            $this->sales->refreshSale($locked);

            // the customer's account
            if (! $customer->is_walk_in) {
                $this->accounts->postEntry($customer, 'sale_return', '0', $total, $return, "Return {$return->return_no} against {$locked->invoice_no}", $by);
            }
            if (Decimal::isPositive($refund)) {
                $payment = $this->payment($customer, $method, 'refund', $refund, "Refund for return {$return->return_no}", $by);
                $return->forceFill(['refund_payment_id' => $payment->id])->save();
                if (! $customer->is_walk_in) {
                    $this->accounts->postEntry($customer, 'refund', $refund, '0', $payment, "Refund {$payment->receipt_no} for return {$return->return_no}", $by);
                }
            }
            if (Decimal::isPositive($credit)) {
                // store credit is a receipt in the "Customer Credit" method with no ledger line of its own (the return already credited the account):
                // that makes it ordinary spendable credit for the till and for deliveries
                $creditMethod = PaymentMethod::query()->where('kind', 'customer_credit')->firstOrFail();
                $payment = $this->payment($customer, $creditMethod, 'receipt', $credit, "Store credit from return {$return->return_no}", $by);
                $return->forceFill(['store_credit_payment_id' => $payment->id])->save();
            }

            $this->audit->log('sale_return_created', 'sales', "Return {$return->return_no} of {$total} against {$locked->invoice_no}", $return, new: [
                'invoice' => $locked->invoice_no, 'customer' => $customer->name, 'total' => $total, 'applied_to_due' => $applied, 'refund' => $refund, 'store_credit' => $credit,
                'lines' => array_map(fn ($b) => ['product' => $b['item']->product_name, 'quantity' => $b['qty'], 'condition' => $b['condition'], 'restock' => $b['restock'], 'value' => Decimal::add($b['net'], $b['tax'], 2)], $built),
                'reason' => $reason,
            ], user: $by);

            return $return->refresh()->load('items');
        }, 3);
    }

    /**
     * What a number of units of this line are worth: their share of the line's net amount and tax. Returning everything that is left of
     * the line takes exactly what is left, so the pieces always add up to the line.
     *
     * @return array{0: string, 1: string} net, tax
     */
    private function value(SaleItem $item, string $qty, string $remaining): array
    {
        if (Decimal::cmp($qty, $remaining) === 0) {
            $given = DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.sale_return_id')
                ->where('ri.sale_item_id', $item->id)->where('r.status', 'posted')
                ->selectRaw('COALESCE(SUM(ri.net_amount), 0) AS net, COALESCE(SUM(ri.tax_amount), 0) AS tax')->first();

            return [Decimal::nonNegative(Decimal::sub((string) $item->net_amount, (string) $given->net, 2), 2), Decimal::nonNegative(Decimal::sub((string) $item->tax_amount, (string) $given->tax, 2), 2)];
        }

        $share = fn (string $amount) => Decimal::round(Decimal::div(Decimal::mul($amount, $qty, 8), (string) $item->quantity, 8), 2);

        return [$share((string) $item->net_amount), $share((string) $item->tax_amount)];
    }

    private function payment(Customer $customer, PaymentMethod $method, string $type, string $amount, string $notes, User $by): CustomerPayment
    {
        $payment = new CustomerPayment;
        $payment->forceFill([
            'receipt_no' => $this->numbers->next('customer_payment'), 'customer_id' => $customer->id, 'payment_date' => now(), 'payment_method_id' => $method->id,
            'type' => $type, 'amount' => $amount, 'notes' => mb_substr($notes, 0, 255), 'status' => 'posted', 'created_by' => $by->id,
        ])->save();

        return $payment;
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
    }
}
