<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Purchasing\DeletePurchaseDraft;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Purchasing\VoidPurchase;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly SavePurchaseDraft $save,
        private readonly DeletePurchaseDraft $delete,
        private readonly ReceivePurchase $receive,
        private readonly VoidPurchase $void,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('purchases.view');
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,draft,received,voided'],
            'payment' => ['nullable', 'in:all,unpaid,partial,paid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Purchase::query()->search($request->input('q'))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when(in_array($request->input('status'), ['draft', 'received', 'voided'], true), fn ($q) => $q->where('status', $request->input('status')))
            ->when(in_array($request->input('payment'), ['unpaid', 'partial', 'paid'], true), fn ($q) => $q->where('status', Purchase::RECEIVED)->where('payment_status', $request->input('payment')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('purchase_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('purchase_date', '<=', $request->input('to')));

        $totals = (clone $query)->reorder()->where('status', Purchase::RECEIVED)->toBase()
            ->selectRaw('COUNT(*) AS bills, COALESCE(SUM(grand_total), 0) AS total, COALESCE(SUM(due_amount), 0) AS due')->first();

        return view('purchases.index', [
            'purchases' => $query->with('supplier:id,name,code')->orderByDesc('purchase_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'totals' => $totals,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('purchases.create');

        return view('purchases.form', $this->formData(new Purchase(['purchase_date' => now()->toDateString()]), $request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('purchases.create');
        $this->guardReceiveRequest($request);
        $data = $this->validated($request);

        try {
            $purchase = $this->save->execute($data, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->afterSave($request, $purchase);
    }

    public function show(Purchase $purchase): View
    {
        $this->authorize('purchases.view');
        $purchase->load(['supplier', 'items.product.unit', 'creator', 'receiver', 'voider', 'allocations.payment.method', 'returns']);

        return view('purchases.show', ['purchase' => $purchase, 'methods' => PaymentMethod::query()->forSuppliers()->get()]);
    }

    public function edit(Request $request, Purchase $purchase): View|RedirectResponse
    {
        $this->authorize('purchases.create');
        if (! $purchase->isDraft()) {
            return redirect()->route('purchases.show', $purchase)->with('error', "{$purchase->label()} has already been received and can no longer be edited.");
        }

        return view('purchases.form', $this->formData($purchase->load('items.product.unit'), $request));
    }

    public function update(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('purchases.create');
        $this->guardReceiveRequest($request);
        $data = $this->validated($request);

        try {
            $purchase = $this->save->execute($data, $request->user(), $purchase);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->afterSave($request, $purchase);
    }

    public function destroy(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('purchases.create');

        try {
            $this->delete->execute($purchase, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.index')->with('success', 'Draft deleted.');
    }

    public function receive(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('purchases.receive');
        $request->validate($this->paymentRules());

        try {
            $purchase = $this->receive->execute($purchase, $request->user(), $this->payment($request));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)->with('success', "Purchase {$purchase->purchase_no} received; the stock has been updated.");
    }

    public function void(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('purchases.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($purchase, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)->with('success', "Purchase {$purchase->purchase_no} cancelled. The goods were taken out of stock and the supplier's account was corrected.");
    }

    public function print(Purchase $purchase): View
    {
        $this->authorize('purchases.view');
        $purchase->load(['supplier', 'items.product.unit', 'creator', 'receiver']);

        return view('purchases.print', ['purchase' => $purchase]);
    }

    /** Product search for the purchase form: includes the last cost paid, in general and to this supplier. */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('purchases.view');
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100'], 'supplier_id' => ['nullable', 'integer']]);

        $rows = Product::query()->with('unit')->active()->stockItems()->search($request->input('q'))->limit(15)->get();
        $supplierId = $request->integer('supplier_id');

        return response()->json($rows->map(function (Product $p) use ($supplierId) {
            $fromSupplier = $supplierId
                ? PurchaseItem::query()->where('product_id', $p->id)
                    ->whereHas('purchase', fn ($q) => $q->where('supplier_id', $supplierId)->where('status', Purchase::RECEIVED))
                    ->orderByDesc('id')->value('unit_cost')
                : null;

            return [
                'id' => $p->id, 'sku' => $p->sku, 'barcode' => $p->barcode, 'name' => $p->name,
                'stock' => Decimal::trim($p->current_stock), 'unit' => $p->unit->short_name, 'allows_decimal' => (bool) $p->unit->allows_decimal,
                'last_cost' => $p->last_purchase_cost !== null ? Decimal::trim($p->last_purchase_cost) : null,
                'supplier_last_cost' => $fromSupplier !== null ? Decimal::trim((string) $fromSupplier) : null,
                'average_cost' => Decimal::trim($p->average_cost),
            ];
        }));
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function afterSave(Request $request, Purchase $purchase): RedirectResponse
    {
        if ($request->input('action') !== 'receive') {
            return redirect()->route('purchases.show', $purchase)->with('success', 'Draft saved. Receive it when the goods arrive.');
        }

        try {
            $purchase = $this->receive->execute($purchase, $request->user(), $this->payment($request));
        } catch (DomainException $e) {
            // The draft itself is safe; only the receiving was refused (and rolled back as a whole).
            return redirect()->route('purchases.edit', $purchase)->with('error', 'The draft was saved but NOT received: '.$e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)->with('success', "Purchase {$purchase->purchase_no} received; the stock has been updated.");
    }

    /** Refuse "save and receive" for someone who may only prepare drafts, before anything is saved. */
    private function guardReceiveRequest(Request $request): void
    {
        if ($request->input('action') === 'receive') {
            $this->authorize('purchases.receive');
        }
    }

    /** @return array{payment_method_id: int, amount: string, reference_no: string|null}|null */
    private function payment(Request $request): ?array
    {
        $amount = $request->input('pay_amount');
        if ($amount === null || $amount === '' || ! Decimal::isPositive((string) $amount)) {
            return null;
        }

        return ['payment_method_id' => $request->integer('pay_method_id'), 'amount' => (string) $amount, 'reference_no' => $request->input('pay_reference')];
    }

    /** @return array<string, mixed> */
    private function paymentRules(): array
    {
        return [
            'pay_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'pay_method_id' => ['nullable', 'required_with:pay_amount', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'pay_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'action' => ['nullable', 'in:draft,receive'],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'supplier_invoice_no' => ['nullable', 'string', 'max:50'],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'additional_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('type', 'stock')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,4'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ] + $this->paymentRules(), [
            'lines.required' => 'Add at least one product.',
            'lines.*.quantity.gt' => 'Enter a quantity greater than zero.',
            'lines.*.product_id.exists' => 'One of the products is not a stock item any more.',
            'lines.*.unit_cost.required' => 'Enter the cost of every product (0 if it was free).',
            'decimal' => 'Too many decimal places.',
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Purchase $purchase, Request $request): array
    {
        $raw = $request->old('lines');
        $rows = $raw !== null
            ? collect($raw)
            : $purchase->items->map(fn (PurchaseItem $i) => [
                'product_id' => $i->product_id, 'quantity' => Decimal::trim((string) $i->quantity), 'unit_cost' => Decimal::trim((string) $i->unit_cost),
                'discount_amount' => Decimal::isZero((string) $i->discount_amount) ? '' : Decimal::trim((string) $i->discount_amount),
                'tax_amount' => Decimal::isZero((string) $i->tax_amount) ? '' : Decimal::trim((string) $i->tax_amount),
            ]);

        $products = Product::query()->withTrashed()->with('unit')->whereIn('id', $rows->pluck('product_id')->filter()->map(fn ($id) => (int) $id))->get()->keyBy('id');
        $lines = [];
        foreach ($rows as $r) {
            $p = $products[(int) ($r['product_id'] ?? 0)] ?? null;
            if (! $p) {
                continue;
            }
            $lines[] = [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'unit' => $p->unit->short_name, 'allows_decimal' => (bool) $p->unit->allows_decimal,
                'stock' => Decimal::trim($p->current_stock), 'quantity' => (string) ($r['quantity'] ?? ''), 'unit_cost' => (string) ($r['unit_cost'] ?? ''),
                'discount_amount' => (string) ($r['discount_amount'] ?? ''), 'tax_amount' => (string) ($r['tax_amount'] ?? ''),
                'last_cost' => $p->last_purchase_cost !== null ? Decimal::trim($p->last_purchase_cost) : null,
                'last_price' => $p->last_purchase_cost !== null ? Decimal::trim($p->last_purchase_cost) : null,
            ];
        }

        return [
            'purchase' => $purchase,
            'suppliers' => Supplier::query()->where('is_active', true)->orWhere('id', $purchase->supplier_id)->orderBy('name')->get(['id', 'name', 'company']),
            'methods' => PaymentMethod::query()->forSuppliers()->get(),
            'lines' => $lines,
            'canReceive' => $request->user()->can('purchases.receive'),
            'taxInCost' => app(SettingsService::class)->bool('tax.purchase_tax_in_cost'),
        ];
    }
}
