<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\TotalMismatchException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccountService;
use App\Services\DocumentNumberService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\PosApprovalService;
use App\Services\Sales\SaleAccountService;
use App\Services\Sales\SaleCalculator;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Completes a sale (SRS POS-17). The browser only sends WHAT is being bought and how it is paid; the server decides every price,
 * discount, tax and total itself, checks that the cashier (or the manager approving) is allowed to do it, and then, in ONE
 * database transaction: numbers the invoice, saves the lines at today's average cost, takes the stock out, records the payments,
 * puts a named customer's invoice (and what they paid) on their account, and audits it. If anything fails (not enough stock, a payment
 * that does not add up ...) nothing at all is saved and the number is given back.
 *
 * Customers: the walk-in customer pays in full. A named customer may pay part (or nothing) when they are allowed credit and, if a credit
 * limit is set, the sale does not take them over it; otherwise a manager must approve. "Customer credit" spends money the customer
 * already paid in advance.
 *
 * Double clicks and retries: every checkout attempt carries a key. The same key never makes a second sale; it returns the first.
 *
 * Lock order (always the same, so two tills never wait on each other in a circle): idempotency lock -> customer (named only) ->
 * products by id -> number sequences -> audit trail.
 */
class CompleteSale
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SaleCalculator $calculator,
        private readonly SaleAccountService $accounts,
        private readonly CustomerAccountService $customers,
        private readonly PosApprovalService $approvals,
        private readonly DocumentNumberService $numbers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     idempotency_key: string, customer_id?: int|null, price_tier?: string|null,
     *     lines: list<array{product_id: int, quantity: string, unit_price?: string|null, discount_amount?: string|null, discount_percent?: string|null, serial_number?: string|null}>,
     *     discount_amount?: string|null, discount_percent?: string|null,
     *     payments?: list<array{payment_method_id: int, amount: string, reference_no?: string|null}>,
     *     notes?: string|null, print_notes?: bool, expected_total?: string|null, approval_token?: string|null, client_ip?: string|null
     * }  $data
     *
     * @throws ApprovalRequiredException a manager must approve first (nothing saved)
     * @throws TotalMismatchException the screen's total differs from the server's (nothing saved)
     * @throws InsufficientStockException not enough stock (nothing saved)
     */
    public function execute(array $data, User $by): Sale
    {
        if (! $by->can('sales.create')) {
            throw new AuthorizationException('You are not allowed to complete sales.');
        }

        $key = (string) ($data['idempotency_key'] ?? '');
        if (! Str::isUuid($key)) {
            throw new InvalidArgumentException('The sale has no valid checkout key.');
        }

        // Serialise attempts with the same key (a double click, or a retry while the first is still running). A named lock is used
        // because row and gap locks on a key that does not exist yet can make unrelated sales wait on each other.
        $lock = 'mrpos.sale.'.$key;
        $got = (int) (DB::selectOne('SELECT GET_LOCK(?, 15) AS l', [$lock])->l ?? 0);
        if ($got !== 1) {
            throw new DomainException('This sale is already being processed. Please wait a moment and look at the sales list before trying again.');
        }

        try {
            if ($existing = Sale::query()->where('idempotency_key', $key)->first()) {
                return $this->replay($existing, $by);
            }

            try {
                return DB::transaction(fn () => $this->post($data, $key, $by), 3);
            } catch (QueryException $e) {
                // Backstop: the unique index caught a duplicate that slipped past the lock (it should not happen).
                if (($e->errorInfo[1] ?? null) === 1062 && ($existing = Sale::query()->where('idempotency_key', $key)->first())) {
                    return $this->replay($existing, $by);
                }
                throw $e;
            }
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function replay(Sale $existing, User $by): Sale
    {
        if ($existing->user_id !== $by->id) {
            throw new DomainException('This checkout key already belongs to a sale made by someone else.');
        }

        return $existing->load('items', 'customer', 'cashier');
    }

    /**
     * Steps 1-4 of a sale: lock the products, price every line on the server, work out the totals, and collect what needs a manager's approval.
     * Shared by the sale itself and by quote().
     *
     * @param  array<string, mixed>  $data
     * @return array{tier: string, inclusive: bool, priced: list<array<string, mixed>>, calc: array<string, mixed>, reasons: array<string, array{code: string, message: string, permission: string}>}
     */
    private function price(array $data, Customer $customer, User $by): array
    {
        $lines = array_values($data['lines'] ?? []);
        if ($lines === [] || count($lines) > 200) {
            throw new DomainException($lines === [] ? 'Add at least one product to the sale.' : 'A sale can have at most 200 lines.');
        }

        $requested = $data['price_tier'] ?? null;
        $tier = $requested === 'wholesale' || ($requested === null && $customer->price_tier === 'wholesale') ? 'wholesale' : 'retail';
        $taxOn = $this->settings->bool('tax.enabled');
        $inclusive = $taxOn && $this->settings->bool('tax.inclusive');
        $defaultRate = $taxOn ? (string) $this->settings->get('tax.default_rate') : '0';
        $roundTo = (int) $this->settings->get('sales.round_to');

        // 1. lock the products in a fixed order, then read nothing else until they are ours
        $products = $this->inventory->lockProducts(array_map(fn ($l) => (int) $l['product_id'], $lines));

        // 2. price every line on the server
        $reasons = [];
        $priced = [];
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            $p = $products[(int) $l['product_id']] ?? null;
            if (! $p || $p->trashed() || ! $p->is_active) {
                throw new DomainException("Line {$n}: the product is no longer available.");
            }

            $qty = Decimal::round((string) $l['quantity'], 3);
            if (! Decimal::isPositive($qty)) {
                throw new DomainException("Line {$n}: enter a quantity greater than zero.");
            }
            if (! $p->unit->allows_decimal && ! Decimal::isWhole($qty)) {
                throw new DomainException("Line {$n}: \"{$p->name}\" is sold in whole {$p->unit->name}s.");
            }

            $list = $tier === 'wholesale' && $p->wholesale_price !== null ? (string) $p->wholesale_price : (string) $p->sale_price;
            $given = $l['unit_price'] ?? null;
            $price = ($given === null || $given === '') ? $list : Decimal::round((string) $given, 2);
            if (Decimal::isNegative($price)) {
                throw new DomainException("Line {$n}: a price cannot be negative.");
            }
            if (Decimal::cmp($price, $list) !== 0 && ! $by->can('sales.change_price')) {
                $reasons['price_change'] ??= ['code' => 'price_change', 'message' => 'a price was changed', 'permission' => 'sales.change_price'];
            }

            $priced[] = [
                'product' => $p, 'quantity' => $qty, 'unit_price' => $price, 'list_price' => $list,
                'discount_amount' => $l['discount_amount'] ?? null, 'discount_percent' => $l['discount_percent'] ?? null,
                'tax_rate' => $p->tax_rate !== null ? (string) $p->tax_rate : $defaultRate,
                'serial_number' => isset($l['serial_number']) && trim((string) $l['serial_number']) !== '' ? mb_substr(trim((string) $l['serial_number']), 0, 100) : null,
            ];
        }
        // the wholesale list is free for a wholesale customer; for anyone else it is a price decision
        if ($tier === 'wholesale' && $customer->price_tier !== 'wholesale' && ! $by->can('sales.change_price')) {
            $reasons['wholesale'] = ['code' => 'wholesale', 'message' => 'the wholesale price list was used', 'permission' => 'sales.change_price'];
        }

        // 3. the totals
        try {
            $calc = $this->calculator->calculate(
                array_map(fn ($x) => [
                    'quantity' => $x['quantity'], 'unit_price' => $x['unit_price'], 'discount_amount' => $x['discount_amount'],
                    'discount_percent' => $x['discount_percent'], 'tax_rate' => $taxOn ? $x['tax_rate'] : '0',
                ], $priced),
                ['discount_amount' => $data['discount_amount'] ?? null, 'discount_percent' => $data['discount_percent'] ?? null, 'tax_inclusive' => $inclusive, 'round_to' => $roundTo],
            );
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage(), 0, $e);
        }

        $expected = $data['expected_total'] ?? null;
        if ($expected !== null && $expected !== '' && Decimal::cmp((string) $expected, $calc['grand_total']) !== 0) {
            throw new TotalMismatchException($calc['grand_total'], Decimal::round((string) $expected, 2));
        }

        // 4. does anything here need a manager?
        $totalDiscount = Decimal::add($calc['line_discount_total'], $calc['invoice_discount_amount'], 2);
        if (Decimal::isPositive($totalDiscount)) {
            if (! $by->can('sales.discount')) {
                $reasons['discount'] = ['code' => 'discount', 'message' => 'a discount was given', 'permission' => 'sales.discount_unlimited'];
            } elseif (! $by->can('sales.discount_unlimited')
                && Decimal::cmp(Decimal::mul($totalDiscount, '100', 4), Decimal::mul((string) $this->settings->get('sales.max_discount_percent'), $calc['items_total'], 4)) > 0) {
                $max = Decimal::trim((string) $this->settings->get('sales.max_discount_percent'));
                $reasons['discount_limit'] = ['code' => 'discount_limit', 'message' => "the discount is more than {$max}%", 'permission' => 'sales.discount_unlimited'];
            }
        }
        foreach ($priced as $i => $x) {
            $min = $x['product']->min_sale_price;
            if ($min === null) {
                continue;
            }
            $c = $calc['lines'][$i];
            $worth = Decimal::sub(Decimal::sub($c['gross'], $c['line_discount'], 2), $c['invoice_discount_share'], 2);
            if (Decimal::cmp($worth, Decimal::mul((string) $min, $x['quantity'], 2)) < 0 && ! $by->can('sales.below_min_price')) {
                $reasons['below_min_price'] = ['code' => 'below_min_price', 'message' => 'an item is sold below its lowest allowed price', 'permission' => 'sales.below_min_price'];
            }
        }

        return ['tier' => $tier, 'inclusive' => $inclusive, 'priced' => $priced, 'calc' => $calc, 'reasons' => $reasons];
    }

    /**
     * What the server would charge for these lines (the total of the invoice), worked out exactly as the sale itself works it out. Nothing is written;
     * call it inside a transaction (it locks the products). The exchange needs it to know how much of a return pays for the new items.
     *
     * @param  array<string, mixed>  $data  the same data as execute()
     */
    public function quote(array $data, User $by): string
    {
        return (string) $this->price($data, $this->customer($data['customer_id'] ?? null), $by)['calc']['grand_total'];
    }

    /** @param  array<string, mixed>  $data */
    private function post(array $data, string $key, User $by): Sale
    {
        // 0. the customer is locked FIRST (a named customer's account is changed by sales, payments and cancellations alike)
        $customer = $this->customer($data['customer_id'] ?? null);

        ['tier' => $tier, 'inclusive' => $inclusive, 'priced' => $priced, 'calc' => $calc, 'reasons' => $reasons] = $this->price($data, $customer, $by);
        $totalDiscount = Decimal::add($calc['line_discount_total'], $calc['invoice_discount_amount'], 2);

        // 5. the payments (and, for a named customer, what stays on their account)
        $plan = $this->payments($data['payments'] ?? [], $calc['grand_total'], $customer, $by, $reasons);

        $approver = null;
        if ($reasons !== []) {
            $token = $data['approval_token'] ?? null;
            $approver = $this->approvals->peek($token, $by);
            $missing = $approver ? collect($reasons)->filter(fn ($r) => ! $approver->can($r['permission']))->all() : $reasons;
            if (! $approver || $missing !== []) {
                throw new ApprovalRequiredException(array_values($missing ?: $reasons));
            }
        }

        // 6. write everything
        $now = now();
        $sale = new Sale;
        $sale->forceFill([
            'invoice_no' => $this->numbers->next('sale'),
            'idempotency_key' => $key,
            'customer_id' => $customer->id,
            'user_id' => $by->id,
            'approved_by' => $approver?->id,
            'sale_date' => $now,
            'price_tier' => $tier,
            'status' => Sale::COMPLETED,
            'tax_inclusive' => $inclusive,
            'items_total' => $calc['items_total'],
            'line_discount_total' => $calc['line_discount_total'],
            'invoice_discount_amount' => $calc['invoice_discount_amount'],
            'invoice_discount_percent' => $calc['invoice_discount_percent'],
            'tax_total' => $calc['tax_total'],
            'round_off' => $calc['round_off'],
            'grand_total' => $calc['grand_total'],
            'amount_tendered' => $plan['tendered'],
            'change_amount' => $plan['change'],
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'print_notes' => (bool) ($data['print_notes'] ?? false),
            'client_ip' => $data['client_ip'] ?? null,
        ])->save();

        // the stock leaves in product-id order (the order the rows were locked in), whatever order the lines were typed in
        $order = array_keys($priced);
        usort($order, fn ($a, $b) => [$priced[$a]['product']->id, $a] <=> [$priced[$b]['product']->id, $b]);

        $summary = [];
        foreach ($order as $i) {
            $x = $priced[$i];
            /** @var Product $p */
            $p = $x['product'];
            $c = $calc['lines'][$i];

            $item = new SaleItem;
            $item->forceFill([
                'sale_id' => $sale->id, 'product_id' => $p->id, 'product_name' => $p->name, 'sku' => $p->sku,
                'quantity' => $x['quantity'], 'list_price' => $x['list_price'], 'unit_price' => $x['unit_price'],
                'discount_amount' => $c['line_discount'], 'invoice_discount_share' => $c['invoice_discount_share'],
                'tax_rate' => $c['tax_rate'], 'tax_amount' => $c['tax_amount'], 'net_amount' => $c['net_amount'], 'line_total' => $c['line_total'],
                'unit_cost' => '0', 'serial_number' => $x['serial_number'],
                'warranty_until' => $p->warranty_months ? $now->copy()->addMonthsNoOverflow((int) $p->warranty_months)->toDateString() : null,
            ])->save();

            $unitCost = '0.0000';
            if ($p->isStockItem()) {
                $movement = $this->inventory->move($p, Decimal::neg($x['quantity'], 3), 'sale', [
                    'source' => $sale, 'source_line_id' => $item->id, 'notes' => "{$sale->invoice_no} · {$p->sku}", 'user' => $by,
                ]);
                $unitCost = (string) $movement->unit_cost;      // the average cost at this moment: it is what profit is measured against
                $item->forceFill(['unit_cost' => $unitCost])->save();
            }
            $summary[$i] = ['sku' => $p->sku, 'qty' => Decimal::trim($x['quantity']), 'price' => $x['unit_price'], 'total' => $c['line_total']];
        }
        ksort($summary);

        // a named customer owes the whole invoice from this moment; what they pay now is credited right after
        if (! $customer->is_walk_in && Decimal::isPositive($calc['grand_total'])) {
            $this->customers->postEntry($customer, 'sale', $calc['grand_total'], '0', $sale, "Sale {$sale->invoice_no}", $by);
        }

        foreach ($plan['lines'] as $pay) {
            $payment = new CustomerPayment;
            $payment->forceFill([
                'receipt_no' => $this->numbers->next('customer_payment'),
                'customer_id' => $customer->id,
                'sale_id' => $sale->id,
                'payment_date' => $now,
                'payment_method_id' => $pay['method']->id,
                'type' => 'receipt',
                'amount' => $pay['amount'],
                'reference_no' => $pay['reference'],
                'notes' => "Sale {$sale->invoice_no}",
                'status' => 'posted',
                'created_by' => $by->id,
            ])->save();
            SalePayment::query()->create(['customer_payment_id' => $payment->id, 'sale_id' => $sale->id, 'amount' => $pay['amount']]);

            if (! $customer->is_walk_in) {
                $this->customers->postEntry($customer, 'payment', '0', $pay['amount'], $payment, "Payment {$payment->receipt_no} · {$pay['method']->name} · {$sale->invoice_no}", $by);
            }
        }

        // money the customer paid in earlier, spent on this invoice (no new money, so no ledger line)
        $creditUsed = [];
        if (Decimal::isPositive($plan['credit'])) {
            $creditUsed = $this->customers->allocateAdvance($customer->id, $sale, $plan['credit']);
        }

        $this->accounts->refreshSale($sale);

        // 7. audit last (the same lock order everywhere keeps two tills from ever waiting on each other in a circle)
        $this->audit->log('sale_completed', 'sales', "Sale {$sale->invoice_no}: {$calc['grand_total']} ({$tier}), ".count($summary).' line(s)', $sale, new: [
            'invoice' => $sale->invoice_no, 'customer' => $customer->is_walk_in ? null : $customer->name, 'total' => $calc['grand_total'],
            'discount' => $totalDiscount, 'round_off' => $calc['round_off'],
            'payments' => array_map(fn ($p) => ['method' => $p['method']->name, 'amount' => $p['amount']], $plan['lines']),
            'customer_credit_used' => $creditUsed, 'on_account' => (string) $sale->due_amount,
            'approved_by' => $approver?->username, 'approval_reasons' => array_keys($reasons), 'lines' => array_slice(array_values($summary), 0, 100),
        ], user: $by);

        // the approval is used up only now that the sale is safely saved
        if ($approver && ! empty($data['approval_token'])) {
            $token = (string) $data['approval_token'];
            DB::afterCommit(fn () => $this->approvals->consume($token));
        }

        return $sale->refresh()->load('items', 'customer', 'cashier');
    }

    /** The walk-in customer, or a named customer (locked first, so everything that changes their account waits its turn). */
    private function customer(?int $id): Customer
    {
        // The locking read must be the FIRST read of the transaction. MariaDB fixes a transaction's snapshot at its first plain read,
        // so a plain read before the lock (even "which customer is the walk-in one?") would leave this sale looking at the
        // customer's credit and invoices as they were BEFORE it waited for another till to finish with them.
        if ($id === null) {
            return Customer::walkIn();
        }

        try {
            $customer = $this->customers->lockCustomer($id);
        } catch (ModelNotFoundException) {
            throw new DomainException('That customer no longer exists.');
        }
        if ($customer->is_walk_in) {
            return $customer;
        }
        if ($customer->trashed() || ! $customer->is_active) {
            throw new DomainException("Customer \"{$customer->name}\" is inactive. Re-activate the customer before selling to them.");
        }

        return $customer;
    }

    /**
     * Turns what the cashier typed into what is recorded.
     *
     *  - only cash may be handed over in excess (the difference is change); card, bank and wallet payments must be exact
     *  - "Customer credit" spends the customer's advance (never more than they have)
     *  - the walk-in customer must pay in full; a named customer may leave the rest on their account if they are allowed credit
     *    (a manager must approve when the cashier may not sell on credit, or the sale takes the customer over their credit limit)
     *
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, array{code: string, message: string, permission: string}>  $reasons  extended with anything that needs approval
     * @return array{lines: list<array{method: PaymentMethod, amount: string, reference: string|null}>, credit: string, tendered: string|null, change: string}
     */
    private function payments(array $payments, string $grandTotal, Customer $customer, User $by, array &$reasons): array
    {
        $lines = [];
        $credit = '0.00';
        foreach (array_values($payments) as $p) {
            $amount = Decimal::round((string) ($p['amount'] ?? '0'), 2);
            if (Decimal::isZero($amount)) {
                continue;   // an empty payment line is ignored
            }
            if (Decimal::isNegative($amount)) {
                throw new DomainException('A payment cannot be negative.');
            }

            $method = PaymentMethod::query()->find((int) ($p['payment_method_id'] ?? 0));
            if (! $method || ! $method->is_active) {
                throw new DomainException('Choose a payment method that can be used at the till.');
            }
            if ($method->kind === 'customer_credit') {
                if ($customer->is_walk_in) {
                    throw new DomainException('Customer credit can only be used by a named customer.');
                }
                $credit = Decimal::add($credit, $amount, 2);

                continue;
            }

            $reference = isset($p['reference_no']) && trim((string) $p['reference_no']) !== '' ? mb_substr(trim((string) $p['reference_no']), 0, 100) : null;
            if ($method->requires_reference && $reference === null) {
                throw new DomainException("{$method->name} payments need a reference (transaction, cheque or slip number).");
            }
            $lines[] = ['method' => $method, 'amount' => $amount, 'reference' => $reference];
        }

        if (Decimal::isPositive($credit)) {
            $advance = $this->customers->advanceFor($customer->id);
            if (Decimal::cmp($credit, $advance) > 0) {
                throw new DomainException("{$customer->name} has only {$advance} of credit, not {$credit}.");
            }
        }

        $real = array_reduce($lines, fn ($c, $l) => Decimal::add($c, $l['amount'], 2), '0.00');
        $paid = Decimal::add($real, $credit, 2);

        if (Decimal::isZero($grandTotal)) {
            if ($lines !== [] || Decimal::isPositive($credit)) {
                throw new DomainException('This sale is free, so there is nothing to pay.');
            }

            return ['lines' => [], 'credit' => '0.00', 'tendered' => null, 'change' => '0.00'];
        }

        if (Decimal::cmp($paid, $grandTotal) < 0) {
            $short = Decimal::sub($grandTotal, $paid, 2);
            if ($customer->is_walk_in) {
                throw new DomainException("The walk-in customer must pay in full: {$short} is still missing. Choose or add the customer to sell on credit.");
            }
            if (! $customer->allow_credit) {
                throw new DomainException("{$customer->name} is not allowed credit, so the sale must be paid in full ({$short} is still missing).");
            }
            if (! $by->can('sales.credit')) {
                $reasons['credit_sale'] = ['code' => 'credit_sale', 'message' => 'part of the sale is left on credit', 'permission' => 'sales.credit'];
            }

            // what the customer would owe after this sale: today's balance, plus the invoice, minus what is credited to the account now
            $after = Decimal::sub(Decimal::add((string) $customer->getRawOriginal('balance'), $grandTotal, 2), $real, 2);
            if ($customer->credit_limit !== null && Decimal::cmp($after, (string) $customer->credit_limit) > 0 && ! $by->can('sales.override_credit_limit')) {
                $limit = Decimal::trim((string) $customer->credit_limit);
                $reasons['credit_limit'] = ['code' => 'credit_limit', 'message' => "the sale takes the customer to {$after}, over their credit limit of {$limit}", 'permission' => 'sales.override_credit_limit'];
            }

            return ['lines' => $lines, 'credit' => $credit, 'tendered' => $this->cashTotal($lines), 'change' => '0.00'];
        }

        $excess = Decimal::sub($paid, $grandTotal, 2);
        $tendered = $this->cashTotal($lines);
        $change = '0.00';

        if (Decimal::isPositive($excess)) {
            if (Decimal::cmp($excess, $tendered ?? '0.00') > 0) {
                throw new DomainException('Only cash can be handed over in excess. Card, bank and wallet payments must be exactly what is paid with them.');
            }
            // the change comes out of the cash lines, last one first
            $left = $excess;
            for ($i = count($lines) - 1; $i >= 0 && Decimal::isPositive($left); $i--) {
                if ($lines[$i]['method']->kind !== 'cash') {
                    continue;
                }
                $take = Decimal::cmp($left, $lines[$i]['amount']) < 0 ? $left : $lines[$i]['amount'];
                $lines[$i]['amount'] = Decimal::sub($lines[$i]['amount'], $take, 2);
                $left = Decimal::sub($left, $take, 2);
            }
            if (collect($lines)->contains(fn ($l) => Decimal::isZero($l['amount']))) {
                throw new DomainException('One payment line is not needed: the other lines already cover the total. Remove it.');
            }
            $change = $excess;
        }

        return ['lines' => $lines, 'credit' => $credit, 'tendered' => $tendered, 'change' => $change];
    }

    /**
     * What was handed over in cash (null when nothing was).
     *
     * @param  list<array{method: PaymentMethod, amount: string}>  $lines
     */
    private function cashTotal(array $lines): ?string
    {
        $cash = array_reduce($lines, fn ($c, $l) => $l['method']->kind === 'cash' ? Decimal::add($c, $l['amount'], 2) : $c, '0.00');

        return Decimal::isPositive($cash) ? $cash : null;
    }
}
