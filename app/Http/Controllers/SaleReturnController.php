<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\ExchangeSale;
use App\Actions\Sales\VoidSaleReturn;
use App\Exceptions\InsufficientStockException;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\Money;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

/** Goods customers bring back: the register, a new return against an invoice, cancelling a return, and the return receipt. */
class SaleReturnController extends Controller
{
    public function __construct(
        private readonly CreateSaleReturn $create,
        private readonly VoidSaleReturn $void,
        private readonly ExchangeSale $exchange,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('sale_returns.view');
        $d = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:posted,voided,all'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $status = $d['status'] ?? 'posted';

        $query = SaleReturn::query()->with(['sale:id,invoice_no', 'customer:id,name,is_walk_in', 'creator:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(! empty($d['from']), fn ($q) => $q->whereDate('return_date', '>=', $d['from']))
            ->when(! empty($d['to']), fn ($q) => $q->whereDate('return_date', '<=', $d['to']))
            ->when(! empty($d['q']), function ($q) use ($d) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($d['q'])).'%';
                $q->where(fn ($w) => $w->where('return_no', 'like', $like)->orWhereHas('sale', fn ($s) => $s->where('invoice_no', 'like', $like))->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)));
            });

        return view('sale-returns.index', [
            'returns' => $query->orderByDesc('return_date')->orderByDesc('id')->paginate(25)->withQueryString(), 'status' => $status,
            'total' => (string) (clone $query)->reorder()->where('status', 'posted')->sum('total'),
        ]);
    }

    /** Step 1: find the invoice. Step 2 (with ?sale=): choose what comes back. */
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('sale_returns.create');
        $request->validate(['sale' => ['nullable', 'integer'], 'invoice' => ['nullable', 'string', 'max:50']]);

        $sale = null;
        if ($request->filled('sale')) {
            $sale = Sale::query()->find($request->integer('sale'));
        } elseif ($request->filled('invoice')) {
            $sale = Sale::query()->where('invoice_no', strtoupper(trim((string) $request->input('invoice'))))->first();
            if (! $sale) {
                return back()->withInput()->with('error', 'No invoice with that number was found.');
            }
        }
        if ($sale && $sale->isVoided()) {
            return redirect()->route('sale-returns.create')->with('error', "{$sale->invoice_no} has been cancelled: nothing can be returned against it.");
        }

        $user = $request->user();

        // the return period (7 days unless the administrator changed it): after it only someone allowed to may let a return through, with a reason
        $days = (int) $this->settings->get('sales.return_days');
        $age = $sale ? (int) $sale->sale_date->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0;
        $period = ['days' => $days, 'age' => $age, 'overdue' => $days > 0 && $age > $days, 'canOverride' => $user->can('sale_returns.override_period')];

        return view('sale-returns.create', [
            'period' => $period,
            'fee' => ['enabled' => $this->settings->bool('sales.restocking_fee_enabled'), 'percent' => (string) $this->settings->get('sales.restocking_fee_percent')],
            'exchange' => $request->boolean('exchange') && $user->can('sales.create'),
            'canSell' => $user->can('sales.create'),
            'sale' => $sale?->load(['items.product', 'customer']),
            'returned' => $sale ? $this->returnedValues($sale) : [],
            'methods' => PaymentMethod::query()->forTill()->get(['id', 'name']), 'conditions' => SaleReturnItem::CONDITIONS,
            'canRefund' => $user->can('sale_returns.refund'), 'storeCredit' => $this->settings->bool('sales.store_credit_enabled'),
        ]);
    }

    public function store(Request $request, Sale $sale): RedirectResponse
    {
        $this->authorize('sale_returns.create');
        $d = $request->validate([
            'lines' => ['required', 'array'], 'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'], 'lines.*.condition' => ['nullable', 'in:resellable,damaged,defective'],
            'lines.*.restock' => ['nullable', 'boolean'], 'remainder' => ['nullable', 'in:refund,credit'], 'refund_method_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'max:255'],
            'waive_fee' => ['nullable', 'boolean'], 'exception_reason' => ['nullable', 'string', 'max:255'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $return = $this->create->execute($sale, $this->returnLines($request, $d['lines']), [
                'remainder' => $d['remainder'] ?? '', 'refund_method_id' => $d['refund_method_id'] ?? null, 'waive_fee' => $request->boolean('waive_fee'), 'exception_reason' => $d['exception_reason'] ?? null,
            ], $d['reason'], $request->user());
        } catch (DomainException|AuthorizationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sale-returns.show', $return)->with('success', "Return {$return->return_no} recorded.")
            ->with('receipt_url', URL::temporarySignedRoute('sale-returns.print', now()->addMinutes(10), ['saleReturn' => $return->id, 'autoprint' => 1]));
    }

    /** One-click exchange: the return and the replacement sale in one all-or-nothing step. */
    public function exchange(Request $request, Sale $sale): RedirectResponse
    {
        $this->authorize('sale_returns.create');
        abort_unless($request->user()->can('sales.create'), 403);
        $d = $request->validate([
            'lines' => ['required', 'array'], 'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'], 'lines.*.condition' => ['nullable', 'in:resellable,damaged,defective'],
            'lines.*.restock' => ['nullable', 'boolean'],
            'replacement' => ['required', 'array', 'min:1', 'max:50'], 'replacement.*.product_id' => ['required', 'integer'], 'replacement.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'mode' => ['nullable', 'in:net,refund'], 'refund_method_id' => ['nullable', 'integer'], 'pay_method_id' => ['nullable', 'integer'], 'tendered' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'reference_no' => ['nullable', 'string', 'max:100'], 'reason' => ['required', 'string', 'max:255'], 'waive_fee' => ['nullable', 'boolean'], 'exception_reason' => ['nullable', 'string', 'max:255'],
            'checkout_key' => ['required', 'uuid'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $done = $this->exchange->execute($sale, $this->returnLines($request, $d['lines']), array_values($d['replacement']), [
                'mode' => $d['mode'] ?? 'net', 'refund_method_id' => $d['refund_method_id'] ?? null, 'pay_method_id' => $d['pay_method_id'] ?? null, 'tendered' => $d['tendered'] ?? null,
                'reference_no' => $d['reference_no'] ?? null, 'waive_fee' => $request->boolean('waive_fee'), 'exception_reason' => $d['exception_reason'] ?? null,
            ], $d['reason'], $d['checkout_key'], $request->user());
        } catch (DomainException|AuthorizationException|InsufficientStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $diff = $done['difference'];
        $note = bccomp($diff, '0', 2) > 0 ? 'The customer paid '.Money::withSymbol($diff).' more.' : (bccomp($diff, '0', 2) < 0 ? 'The new items cost '.Money::withSymbol(ltrim($diff, '-')).' less than the return.' : 'An even exchange.');

        return redirect()->route('sale-returns.show', $done['return'])->with('success', "Exchange done: {$done['return']->return_no} for {$done['sale']->invoice_no}. {$note}")
            ->with('receipt_url', URL::temporarySignedRoute('sale-returns.print', now()->addMinutes(10), ['saleReturn' => $done['return']->id, 'autoprint' => 1]))
            ->with('sale_receipt_url', URL::temporarySignedRoute('sales.print', now()->addMinutes(10), ['sale' => $done['sale']->id, 'format' => 'thermal', 'autoprint' => 1]));
    }

    /** The item picker of the exchange: name, SKU and the normal price (for the estimate only; the server prices the sale). */
    public function products(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sale_returns.create') && $request->user()->can('sales.create'), 403);
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);

        return response()->json(Product::query()->active()->search($request->input('q'))->limit(15)->get()->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'price' => (string) $p->sale_price]));
    }

    public function show(SaleReturn $saleReturn): View
    {
        $this->authorize('sale_returns.view');
        $saleReturn->load(['sale', 'customer', 'items.product', 'items.saleItem', 'refundPayment.method', 'creditPayment', 'exchangeSale', 'creator', 'voider']);

        return view('sale-returns.show', ['return' => $saleReturn, 'receiptUrl' => session('receipt_url'), 'saleReceiptUrl' => session('sale_receipt_url')]);
    }

    public function voidReturn(Request $request, SaleReturn $saleReturn): RedirectResponse
    {
        $this->authorize('sales.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($saleReturn, $d['reason'], $request->user());
        } catch (DomainException|AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sale-returns.show', $saleReturn)->with('success', "Return {$saleReturn->return_no} cancelled.");
    }

    public function print(Request $request, SaleReturn $saleReturn): View
    {
        return $this->document($saleReturn, false, $request->boolean('autoprint'));
    }

    public function reprint(Request $request, SaleReturn $saleReturn): View
    {
        $this->authorize('sale_returns.view');
        abort_unless($request->user()->can('sales.reprint'), 403);
        $this->audit->log('sale_return_reprinted', 'sales', "Return receipt {$saleReturn->return_no} reprinted by {$request->user()->name}", $saleReturn, user: $request->user());

        return $this->document($saleReturn, true, false);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @param  array<int|string, array<string, mixed>>  $lines  the form's lines keyed by invoice line id; only the ones with a quantity count */
    private function returnLines(Request $request, array $lines): array
    {
        $out = [];
        foreach ($lines as $itemId => $l) {
            if (isset($l['quantity']) && (float) $l['quantity'] > 0) {
                $out[] = ['sale_item_id' => (int) $itemId, 'quantity' => (string) $l['quantity'], 'condition' => $l['condition'] ?? 'resellable', 'restock' => $request->boolean("lines.{$itemId}.restock")];
            }
        }

        return $out;
    }

    /** For each invoice line: how much has been returned, and the net and tax value already given back (to show what is left). */
    private function returnedValues(Sale $sale): array
    {
        $rows = SaleReturnItem::query()->join('sale_returns as r', 'r.id', '=', 'sale_return_items.sale_return_id')
            ->where('r.sale_id', $sale->id)->where('r.status', 'posted')
            ->selectRaw('sale_return_items.sale_item_id, SUM(sale_return_items.quantity) AS qty, SUM(sale_return_items.net_amount + sale_return_items.tax_amount) AS value, SUM(sale_return_items.net_amount) AS net')
            ->groupBy('sale_return_items.sale_item_id')->get();

        return $rows->mapWithKeys(fn ($r) => [$r->sale_item_id => ['qty' => (string) $r->qty, 'value' => (string) $r->value, 'net' => (string) $r->net]])->all();
    }

    private function document(SaleReturn $return, bool $duplicate, bool $autoprint): View
    {
        $return->load(['sale', 'customer', 'items.product', 'items.saleItem', 'refundPayment.method', 'exchangeSale', 'creator']);

        $barcode = null;
        try {
            $barcode = (new BarcodeGeneratorSVG)->getBarcode($return->return_no, BarcodeGenerator::TYPE_CODE_128, 1.4, 34);
        } catch (Throwable) {
            // the receipt is still valid without a barcode
        }
        $logo = null;
        $path = $this->settings->string('shop.logo_path');
        if ($this->settings->bool('receipt.show_logo') && $path !== '' && Storage::disk('local')->exists($path)) {
            $logo = route('branding.logo');
        }

        return view('sale-returns.print', [
            'return' => $return, 'duplicate' => $duplicate, 'autoprint' => $autoprint, 'barcode' => $barcode, 'logo' => $logo, 's' => $this->settings,
            'copies' => max(1, (int) $this->settings->get('printing.copies')),
        ]);
    }
}
