<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Purchasing\AllocateSupplierAdvance;
use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\VoidSupplierPayment;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Purchasing\SupplierAccountService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierPaymentController extends Controller
{
    public function __construct(
        private readonly PostSupplierPayment $post,
        private readonly VoidSupplierPayment $void,
        private readonly AllocateSupplierAdvance $allocate,
        private readonly SupplierAccountService $accounts,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('supplier_payments.view');
        $request->validate([
            'supplier_id' => ['nullable', 'integer'], 'method' => ['nullable', 'integer'], 'status' => ['nullable', 'in:all,posted,voided'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = SupplierPayment::query()
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method_id', $request->integer('method')))
            ->when(in_array($request->input('status'), ['posted', 'voided'], true), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('payment_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('payment_date', '<=', $request->input('to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $request->input('q'))).'%';
                $q->where(fn ($w) => $w->where('payment_no', 'like', $like)->orWhere('reference_no', 'like', $like));
            });

        $total = (clone $query)->reorder()->where('status', 'posted')->sum('amount');

        return view('supplier-payments.index', [
            'payments' => $query->with(['supplier:id,name,code', 'method:id,name'])->orderByDesc('payment_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::query()->orderBy('sort_order')->get(['id', 'name']),
            'total' => (string) $total,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('supplier_payments.create');
        $request->validate(['supplier' => ['nullable', 'integer'], 'purchase' => ['nullable', 'integer']]);

        $supplier = $request->filled('supplier') ? Supplier::query()->withTrashed()->find($request->integer('supplier')) : null;
        if (! $supplier && $request->filled('purchase')) {
            $supplier = Purchase::query()->find($request->integer('purchase'))?->supplier;
        }

        $bills = $supplier ? Purchase::query()->where('supplier_id', $supplier->id)->owing()->get() : collect();

        return view('supplier-payments.create', [
            'supplier' => $supplier,
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'balance']),
            'methods' => PaymentMethod::query()->forSuppliers()->get(),
            'bills' => $bills,
            'owed' => $bills->reduce(fn ($c, $b) => bcadd($c, (string) $b->due_amount, 2), '0.00'),
            'focus' => $request->integer('purchase') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('supplier_payments.create');
        $d = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'allocation' => ['required', 'in:auto,manual,advance'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $payment = $this->post->execute([
                'supplier_id' => (int) $d['supplier_id'], 'payment_date' => $d['payment_date'], 'payment_method_id' => (int) $d['payment_method_id'],
                'amount' => (string) $d['amount'], 'reference_no' => $d['reference_no'] ?? null, 'notes' => $d['notes'] ?? null,
                'allocation' => $d['allocation'], 'allocations' => array_filter($d['allocations'] ?? [], fn ($v) => $v !== null && $v !== ''),
            ], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('supplier-payments.show', $payment)->with('success', "Payment {$payment->payment_no} recorded.");
    }

    public function show(SupplierPayment $payment): View
    {
        $this->authorize('supplier_payments.view');
        $payment->load(['supplier', 'method', 'creator', 'voider', 'allocations.purchase']);

        $advance = $payment->advanceAmount();
        $bills = $payment->isVoided() || bccomp($advance, '0', 2) <= 0
            ? collect()
            : Purchase::query()->where('supplier_id', $payment->supplier_id)->owing()->get();

        return view('supplier-payments.show', ['payment' => $payment, 'advance' => $advance, 'bills' => $bills]);
    }

    public function void(Request $request, SupplierPayment $payment): RedirectResponse
    {
        $this->authorize('supplier_payments.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($payment, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('supplier-payments.show', $payment)->with('success', "Payment {$payment->payment_no} cancelled; the bills it settled are open again.");
    }

    public function allocate(Request $request, SupplierPayment $payment): RedirectResponse
    {
        $this->authorize('supplier_payments.create');
        $d = $request->validate([
            'allocations' => ['required', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $this->allocate->execute($payment, array_filter($d['allocations'], fn ($v) => $v !== null && $v !== ''), $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('supplier-payments.show', $payment)->with('success', 'The advance has been applied to the bills.');
    }
}
