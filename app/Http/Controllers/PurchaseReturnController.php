<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\VoidPurchaseReturn;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Goods sent back to a supplier: the register, a new return (against a purchase or without one), cancelling it, and the return note. */
class PurchaseReturnController extends Controller
{
    public function __construct(
        private readonly CreatePurchaseReturn $create,
        private readonly VoidPurchaseReturn $void,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('purchase_returns.view');
        $d = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'supplier_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:posted,voided,all'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $status = $d['status'] ?? 'posted';

        $query = PurchaseReturn::query()->with(['supplier:id,name,code', 'purchase:id,purchase_no', 'creator:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(! empty($d['supplier_id']), fn ($q) => $q->where('supplier_id', (int) $d['supplier_id']))
            ->when(! empty($d['from']), fn ($q) => $q->whereDate('return_date', '>=', $d['from']))
            ->when(! empty($d['to']), fn ($q) => $q->whereDate('return_date', '<=', $d['to']))
            ->when(! empty($d['q']), function ($q) use ($d) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($d['q'])).'%';
                $q->where(fn ($w) => $w->where('return_no', 'like', $like)->orWhereHas('purchase', fn ($p) => $p->where('purchase_no', 'like', $like))->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like)));
            });

        return view('purchase-returns.index', [
            'returns' => $query->orderByDesc('return_date')->orderByDesc('id')->paginate(25)->withQueryString(), 'status' => $status,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'total' => (string) (clone $query)->reorder()->where('status', 'posted')->sum('total'),
        ]);
    }

    /** Step 1: pick the purchase (or the supplier, for goods without a bill). Step 2: choose what goes back. */
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('purchase_returns.create');
        $request->validate(['purchase' => ['nullable', 'integer'], 'number' => ['nullable', 'string', 'max:50'], 'supplier' => ['nullable', 'integer']]);

        $purchase = null;
        $supplier = null;
        if ($request->filled('purchase')) {
            $purchase = Purchase::query()->find($request->integer('purchase'));
        } elseif ($request->filled('number')) {
            $purchase = Purchase::query()->where('purchase_no', strtoupper(trim((string) $request->input('number'))))->first();
            if (! $purchase) {
                return back()->withInput()->with('error', 'No purchase with that number was found.');
            }
        } elseif ($request->filled('supplier')) {
            $supplier = Supplier::query()->find($request->integer('supplier'));
        }
        if ($purchase && ! $purchase->isReceived()) {
            return redirect()->route('purchase-returns.create')->with('error', $purchase->isVoided() ? "{$purchase->label()} has been cancelled: nothing can be sent back against it." : 'Only a received purchase can have goods sent back.');
        }

        $user = $request->user();

        return view('purchase-returns.create', [
            'purchase' => $purchase?->load(['items.product.unit', 'supplier']),
            'supplier' => $supplier,
            'returned' => $purchase ? $this->returnedValues($purchase) : [],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::query()->forSuppliers()->get(['id', 'name', 'requires_reference']),
            'canRefund' => $user->can('supplier_payments.create'),
        ]);
    }

    public function store(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('purchase_returns.create');
        $d = $request->validate([
            'lines' => ['required', 'array'], 'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'],
            'settlement' => ['nullable', 'in:adjust_balance,refund'], 'payment_method_id' => ['nullable', 'integer'], 'reference_no' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:255'],
        ], ['decimal' => 'Too many decimal places.']);

        $lines = [];
        foreach ($d['lines'] as $itemId => $l) {
            if (isset($l['quantity']) && (float) $l['quantity'] > 0) {
                $lines[] = ['purchase_item_id' => (int) $itemId, 'quantity' => (string) $l['quantity']];
            }
        }

        return $this->run($request, ['purchase_id' => $purchase->id, 'lines' => $lines] + $d);
    }

    public function storeFree(Request $request): RedirectResponse
    {
        $this->authorize('purchase_returns.create');
        $d = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'], 'lines.*.unit_credit' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,4'],
            'settlement' => ['nullable', 'in:adjust_balance,refund'], 'payment_method_id' => ['nullable', 'integer'], 'reference_no' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:255'],
        ], ['decimal' => 'Too many decimal places.']);

        return $this->run($request, $d);
    }

    public function show(PurchaseReturn $purchaseReturn): View
    {
        $this->authorize('purchase_returns.view');

        return view('purchase-returns.show', ['return' => $purchaseReturn->load(['supplier', 'purchase', 'items.product', 'refundPayment.method', 'creator', 'voider'])]);
    }

    public function voidReturn(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $this->authorize('purchases.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($purchaseReturn, $d['reason'], $request->user());
        } catch (DomainException|AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-returns.show', $purchaseReturn)->with('success', "Return {$purchaseReturn->return_no} cancelled.");
    }

    /** The return note to hand to the supplier (A4). */
    public function print(PurchaseReturn $purchaseReturn): View
    {
        $this->authorize('purchase_returns.view');

        return view('purchase-returns.print', ['return' => $purchaseReturn->load(['supplier', 'purchase', 'items.product.unit', 'refundPayment.method', 'creator'])]);
    }

    /** For each bill line: the value (net + tax) already credited by posted returns, so the form can work out the credit for the last units. */
    private function returnedValues(Purchase $purchase): array
    {
        $rows = PurchaseReturnItem::query()->join('purchase_returns as r', 'r.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('r.purchase_id', $purchase->id)->where('r.status', 'posted')
            ->selectRaw('purchase_return_items.purchase_item_id AS item_id, SUM(purchase_return_items.net_amount + purchase_return_items.tax_amount) AS value')
            ->groupBy('purchase_return_items.purchase_item_id')->get();

        return $rows->mapWithKeys(fn ($r) => [$r->item_id => (string) $r->value])->all();
    }

    private function run(Request $request, array $data): RedirectResponse
    {
        try {
            $return = $this->create->execute($data, $request->user());
        } catch (DomainException|AuthorizationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-returns.show', $return)->with('success', "Return {$return->return_no} recorded.");
    }
}
