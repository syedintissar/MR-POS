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
use App\Services\DocumentNumberService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\PosApprovalService;
use App\Services\Sales\SaleAccountService;
use App\Services\Sales\SaleCalculator;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Completes a sale (SRS POS-17). The browser only sends WHAT is being bought and how it is paid; the server decides every price,
 * discount, tax and total itself, checks that the cashier (or the manager approving) is allowed to do it, and then, in ONE
 * database transaction: numbers the invoice, saves the lines at today's average cost, takes the stock out, records the payments and
 * audits it. If anything fails (not enough stock, a payment that does not add up ...) nothing at all is saved and the number is given back.
 *
 * Double clicks and retries: every checkout attempt carries a key. The same key never makes a second sale; it returns the first.
 *
 * Lock order (always the same, so two tills never wait on each other in a circle): idempotency lock -> products by id ->
 * number sequences -> audit trail.
 */
class CompleteSale
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SaleCalculator $calculator,
        private readonly SaleAccountService $accounts,
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

    /** @param  array<string, mixed>  $data */
    private function post(array $data, string $key, User $by): Sale
    {
        $customer = $this->customer($data['customer_id'] ?? null);

        $lines = array_values($data['lines'] ?? []);
        if ($lines === [] || count($lines) > 200) {
            throw new DomainException($lines === [] ? 'Add at least one product to the sale.' : 'A sale can have at most 200 lines.');
        }

        $tier = ($data['price_tier'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
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
        if ($tier === 'wholesale' && ! $by->can('sales.change_price')) {
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

        $approver = null;
        if ($reasons !== []) {
            $token = $data['approval_token'] ?? null;
            $approver = $this->approvals->peek($token, $by);
            $missing = $approver ? collect($reasons)->filter(fn ($r) => ! $approver->can($r['permission']))->all() : $reasons;
            if (! $approver || $missing !== []) {
                throw new ApprovalRequiredException(array_values($missing ?: $reasons));
            }
        }

        // 5. the payments
        [$applied, $tendered, $change] = $this->payments($data['payments'] ?? [], $calc['grand_total']);

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
            'amount_tendered' => $tendered,
            'change_amount' => $change,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'print_notes' => (bool) ($data['print_notes'] ?? false),
            'client_ip' => $data['client_ip'] ?? null,
        ])->save();

        // the stock leaves in product-id order (the order the rows were locked in), whatever order the lines were typed in
        $order = array_keys($priced);
        usort($order, fn ($a, $b) => [$priced[$a]['product']->id, $a] <=> [$priced[$b]['product']->id, $b]);

        $itemIds = [];
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
            $itemIds[$i] = $item->id;

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

        foreach ($applied as $pay) {
            $payment = new CustomerPayment;
            $payment->forceFill([
                'receipt_no' => $this->numbers->next('customer_payment'),
                'customer_id' => $customer->id,
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
        }
        $this->accounts->refreshSale($sale);

        // 7. audit last (the same lock order everywhere keeps two tills from ever waiting on each other in a circle)
        $this->audit->log('sale_completed', 'sales', "Sale {$sale->invoice_no}: {$calc['grand_total']} ({$tier}), ".count($summary).' line(s)', $sale, new: [
            'invoice' => $sale->invoice_no, 'total' => $calc['grand_total'], 'discount' => $totalDiscount, 'round_off' => $calc['round_off'],
            'payments' => array_map(fn ($p) => ['method' => $p['method']->name, 'amount' => $p['amount']], $applied),
            'approved_by' => $approver?->username, 'approval_reasons' => array_keys($reasons), 'lines' => array_slice(array_values($summary), 0, 100),
        ], user: $by);

        // the approval is used up only now that the sale is safely saved
        if ($approver && ! empty($data['approval_token'])) {
            $token = (string) $data['approval_token'];
            DB::afterCommit(fn () => $this->approvals->consume($token));
        }

        return $sale->refresh()->load('items', 'customer', 'cashier');
    }

    private function customer(?int $id): Customer
    {
        $walkIn = Customer::walkIn();
        if ($id !== null && $id !== $walkIn->id) {
            throw new DomainException('Named customers and credit sales come with the customer module. Use the walk-in customer for now.');
        }

        return $walkIn;
    }

    /**
     * Turns what the cashier typed into what is recorded: the walk-in customer must pay in full; only cash may be handed over in
     * excess (the difference is change); card, bank and wallet payments must be exact.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return array{0: list<array{method: PaymentMethod, amount: string, reference: string|null}>, 1: string|null, 2: string} applied lines, cash tendered, change
     */
    private function payments(array $payments, string $grandTotal): array
    {
        $lines = [];
        foreach (array_values($payments) as $i => $p) {
            $amount = Decimal::round((string) ($p['amount'] ?? '0'), 2);
            if (Decimal::isZero($amount)) {
                continue;   // an empty payment line is ignored
            }
            if (Decimal::isNegative($amount)) {
                throw new DomainException('A payment cannot be negative.');
            }

            $method = PaymentMethod::query()->find((int) ($p['payment_method_id'] ?? 0));
            if (! $method || ! $method->is_active || $method->kind === 'customer_credit') {
                throw new DomainException('Choose a payment method that can be used at the till.');
            }
            $reference = isset($p['reference_no']) && trim((string) $p['reference_no']) !== '' ? mb_substr(trim((string) $p['reference_no']), 0, 100) : null;
            if ($method->requires_reference && $reference === null) {
                throw new DomainException("{$method->name} payments need a reference (transaction, cheque or slip number).");
            }
            $lines[] = ['method' => $method, 'amount' => $amount, 'reference' => $reference];
        }

        $paid = array_reduce($lines, fn ($c, $l) => Decimal::add($c, $l['amount'], 2), '0.00');

        if (Decimal::isZero($grandTotal)) {
            if ($lines !== []) {
                throw new DomainException('This sale is free, so there is nothing to pay.');
            }

            return [[], null, '0.00'];
        }

        if (Decimal::cmp($paid, $grandTotal) < 0) {
            $short = Decimal::sub($grandTotal, $paid, 2);
            throw new DomainException("The walk-in customer must pay in full: {$short} is still missing. Partial payment and credit come with named customers.");
        }

        $excess = Decimal::sub($paid, $grandTotal, 2);
        $tendered = null;
        $change = '0.00';

        $cashTotal = array_reduce($lines, fn ($c, $l) => $l['method']->kind === 'cash' ? Decimal::add($c, $l['amount'], 2) : $c, '0.00');
        if (Decimal::isPositive($cashTotal)) {
            $tendered = $cashTotal;
        }

        if (Decimal::isPositive($excess)) {
            if (Decimal::cmp($excess, $cashTotal) > 0) {
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

        return [$lines, $tendered, $change];
    }
}
