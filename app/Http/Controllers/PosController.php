<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Sales\CompleteSale;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\TotalMismatchException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Customers\CustomerAccountService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\PosApprovalService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The till. The page and its JSON endpoints. Prices and totals shown here are conveniences only: CompleteSale works out
 * every figure again on the server, and nothing about cost ever leaves this controller.
 */
class PosController extends Controller
{
    private const MAX_HELD_PER_USER = 30;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly CompleteSale $complete,
        private readonly PosApprovalService $approvals,
        private readonly CustomerAccountService $customers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('pos.access');
        $user = $request->user();
        $s = $this->settings;
        $taxOn = $s->bool('tax.enabled');
        $money = $user->can('customer_payments.view');

        // a customer chosen on their profile page ("Sell to this customer")
        $preselected = null;
        if ($request->filled('customer') && $user->can('customers.view')) {
            $c = Customer::query()->where('is_walk_in', false)->where('is_active', true)->find($request->integer('customer'));
            $preselected = $c ? $this->customers->summary($c, $money) : null;
        }

        return view('pos.index', [
            'categories' => Category::query()->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'config' => [
                'currency' => $s->string('currency.symbol') ?: 'Rs.',
                'decimals' => $s->string('currency.decimals') === '0' ? 0 : 2,
                'taxEnabled' => $taxOn,
                'taxInclusive' => $taxOn && $s->bool('tax.inclusive'),
                'taxLabel' => $s->string('tax.label'),
                'roundTo' => (int) $s->get('sales.round_to'),
                'maxDiscountPercent' => (string) $s->get('sales.max_discount_percent'),
                'allowNegative' => app(InventoryService::class)->allowNegativeStock(),
                'defaultFormat' => $s->string('printing.default_format') === 'a4' ? 'a4' : 'thermal',
                'autoPrint' => $s->bool('printing.auto_print_sale'),
                'can' => [
                    'changePrice' => $user->can('sales.change_price'),
                    'discount' => $user->can('sales.discount'),
                    'discountUnlimited' => $user->can('sales.discount_unlimited'),
                    'hold' => $user->can('sales.hold'),
                    'complete' => $user->can('sales.create'),
                    'credit' => $user->can('sales.credit'),
                    'overrideLimit' => $user->can('sales.override_credit_limit'),
                    'customers' => $user->can('customers.view'),
                    'addCustomer' => $user->can('customers.create'),
                    'seeBalance' => $money,
                ],
                'creditMethodId' => PaymentMethod::query()->where('kind', 'customer_credit')->value('id'),
                'customer' => $preselected,
                'methods' => PaymentMethod::query()->forTill()->get(['id', 'name', 'kind', 'requires_reference'])
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'kind' => $m->kind, 'ref' => (bool) $m->requires_reference])->values(),
                'urls' => [
                    'search' => route('pos.products.search'), 'quick' => route('pos.products.quick'), 'sale' => route('pos.sales.store'),
                    'approval' => route('pos.approvals'), 'held' => route('pos.held.index'), 'sales' => route('sales.index'),
                    'customerSearch' => route('pos.customers.search'), 'customerStore' => route('pos.customers.store'),
                ],
            ],
        ]);
    }

    /** Search by name, SKU, barcode or model; or browse a category. Never returns cost or the lowest allowed price. */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('pos.access');
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer'], 'ids' => ['nullable', 'string', 'max:2000']]);

        // today's price and stock of products already in the cart (after a "total changed" answer)
        if ($request->filled('ids')) {
            $ids = collect(explode(',', (string) $request->input('ids')))->map(fn ($id) => (int) $id)->filter()->unique()->take(200)->all();

            return response()->json(Product::query()->with('unit')->active()->whereIn('id', $ids)->get()->map(fn (Product $p) => $this->productRow($p))->values());
        }

        $term = trim((string) $request->input('q'));
        if ($term === '' && ! $request->filled('category')) {
            return response()->json([]);
        }

        $rows = Product::query()->with('unit')->active()->search($term)
            ->when($request->filled('category'), function ($q) use ($request) {
                $root = $request->integer('category');
                $ids = Category::query()->where('id', $root)->orWhere('parent_id', $root)->pluck('id');

                return $q->whereIn('category_id', $ids)->orderBy('name');
            })
            ->limit(24)->get();

        return response()->json($rows->map(fn (Product $p) => $this->productRow($p))->values());
    }

    /** The shop's fast movers (last 30 days); until there are enough sales, the most recently touched products. */
    public function quick(): JsonResponse
    {
        $this->authorize('pos.access');

        $ids = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.status', Sale::COMPLETED)->where('s.sale_date', '>=', now()->subDays(30))
            ->groupBy('i.product_id')->orderByRaw('SUM(i.quantity) DESC')->limit(12)->pluck('i.product_id')->all();

        $products = Product::query()->with('unit')->active()->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids, true))->values();
        if ($products->count() < 12) {
            $more = Product::query()->with('unit')->active()->whereNotIn('id', $ids)->where(fn ($q) => $q->where('type', Product::TYPE_SERVICE)->orWhere('current_stock', '>', 0))
                ->orderByDesc('updated_at')->limit(12 - $products->count())->get();
            $products = $products->concat($more);
        }

        return response()->json($products->map(fn (Product $p) => $this->productRow($p))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('pos.access');
        $user = $request->user();

        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'integer'],
            'price_tier' => ['nullable', 'in:retail,wholesale'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'payments' => ['nullable', 'array', 'max:6'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'print_notes' => ['nullable', 'boolean'],
            'expected_total' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'approval_token' => ['nullable', 'string', 'max:80'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $sale = $this->complete->execute($d + ['client_ip' => $request->ip()], $user);
        } catch (ApprovalRequiredException $e) {
            return response()->json(['error' => 'approval_required', 'message' => $e->getMessage(), 'reasons' => $e->reasons], 409);
        } catch (TotalMismatchException $e) {
            return response()->json(['error' => 'total_mismatch', 'message' => $e->getMessage(), 'server_total' => $e->serverTotal], 409);
        } catch (InsufficientStockException $e) {
            return response()->json(['error' => 'insufficient_stock', 'message' => $e->getMessage()], 422);
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (DomainException|InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        $signed = fn (string $format) => URL::temporarySignedRoute('sales.print', now()->addMinutes(10), ['sale' => $sale->id, 'format' => $format, 'autoprint' => 1]);

        return response()->json([
            'sale_id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'total' => (string) $sale->grand_total,
            'paid' => (string) $sale->paid_amount,
            'due' => (string) $sale->due_amount,
            'customer' => $sale->customer->is_walk_in ? null : $sale->customer->name,
            'change' => (string) $sale->change_amount,
            'tendered' => $sale->amount_tendered !== null ? (string) $sale->amount_tendered : null,
            'replayed' => ! $sale->wasRecentlyCreated,
            'print' => ['thermal' => $signed('thermal'), 'a4' => $signed('a4')],
            'view' => route('sales.show', $sale),
        ], $sale->wasRecentlyCreated ? 201 : 200);
    }

    /** A manager types their own username and password on the cashier's screen; the answer is a short-lived, single-use token. */
    public function approve(Request $request): JsonResponse
    {
        $this->authorize('pos.access');
        $d = $request->validate(['username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:200']]);

        try {
            $grant = $this->approvals->grant($request->user(), $d['username'], $d['password']);
        } catch (DomainException $e) {
            return response()->json(['error' => 'refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['token' => $grant['token'], 'approver' => $grant['approver']->name, 'seconds' => PosApprovalService::TTL_SECONDS]);
    }

    // ------------------------------------------------------------------------------------------------ held carts

    public function held(Request $request): JsonResponse
    {
        $this->authorize('sales.hold');

        return response()->json(HeldSale::query()->where('user_id', $request->user()->id)->orderByDesc('id')->get()->map(fn (HeldSale $h) => [
            'id' => $h->id, 'label' => $h->label, 'lines' => count($h->cart['lines'] ?? []), 'total' => $h->cart['meta']['total'] ?? null,
            'at' => $h->created_at->format('d M, H:i'),
        ])->values());
    }

    public function hold(Request $request): JsonResponse
    {
        $this->authorize('sales.hold');
        $d = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'cart' => ['required', 'array'],
            'cart.lines' => ['required', 'array', 'min:1', 'max:200'],
            'cart.lines.*.id' => ['required', 'integer'],
            'cart.lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'cart.customer_id' => ['nullable', 'integer'],
        ]);
        $cart = $request->input('cart');   // the whole cart as the screen sent it (validate() only hands back the fields it checked)
        abort_if(strlen((string) json_encode($cart)) > 100000, 422, 'This cart is too large to hold.');

        if (HeldSale::query()->where('user_id', $request->user()->id)->count() >= self::MAX_HELD_PER_USER) {
            return response()->json(['error' => 'too_many', 'message' => 'You already hold '.self::MAX_HELD_PER_USER.' carts. Resume or discard some first.'], 422);
        }

        $label = trim((string) ($d['label'] ?? '')) ?: 'Cart '.now()->format('H:i');
        $held = HeldSale::query()->create(['user_id' => $request->user()->id, 'customer_id' => Customer::query()->where('is_walk_in', false)->whereKey((int) ($cart['customer_id'] ?? 0))->value('id'), 'label' => $label, 'cart' => $cart]);

        return response()->json(['id' => $held->id, 'label' => $held->label], 201);
    }

    /** The saved cart plus today's price and stock of each product, so the screen can warn about anything that changed. */
    public function resume(Request $request, HeldSale $held): JsonResponse
    {
        $this->authorize('sales.hold');
        abort_unless($held->user_id === $request->user()->id, 403);

        $ids = collect($held->cart['lines'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $products = Product::query()->with('unit')->withTrashed()->whereIn('id', $ids)->get()->map(fn (Product $p) => $this->productRow($p) + ['available' => $p->is_active && ! $p->trashed()]);

        $customer = $held->customer_id ? Customer::query()->where('is_walk_in', false)->find($held->customer_id) : null;

        return response()->json([
            'id' => $held->id, 'label' => $held->label, 'cart' => $held->cart, 'products' => $products->values(),
            'customer' => $customer ? $this->customers->summary($customer, $request->user()->can('customer_payments.view')) : null,
        ]);
    }

    public function discard(Request $request, HeldSale $held): JsonResponse
    {
        $this->authorize('sales.hold');
        abort_unless($held->user_id === $request->user()->id, 403);
        $held->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function productRow(Product $p): array
    {
        $taxOn = $this->settings->bool('tax.enabled');
        $stock = $p->isStockItem();

        return [
            'id' => $p->id, 'sku' => $p->sku, 'barcode' => $p->barcode, 'name' => $p->name,
            'unit' => $p->unit->short_name, 'allows_decimal' => (bool) $p->unit->allows_decimal,
            'is_service' => ! $stock, 'stock' => $stock ? Decimal::trim((string) $p->current_stock) : null,
            'price' => (string) $p->sale_price, 'wholesale_price' => $p->wholesale_price !== null ? (string) $p->wholesale_price : null,
            'tax_rate' => $taxOn ? Decimal::round($p->tax_rate !== null ? (string) $p->tax_rate : (string) $this->settings->get('tax.default_rate'), 2) : '0',
            'warranty_months' => $p->warranty_months,
        ];
    }
}
