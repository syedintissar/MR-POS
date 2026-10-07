<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Purchasing\AdjustSupplierBalance;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\Purchasing\SupplierAccountService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Supplier master data and the supplier's account (profile, statement, balance adjustment).
 * What the shop owes a supplier is never edited here: it comes only from the supplier ledger, which purchases,
 * payments and the deliberate, password-confirmed balance adjustment write to.
 */
class SupplierController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly DocumentNumberService $numbers) {}

    public function index(Request $request): View
    {
        $this->authorize('suppliers.view');
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive,all']]);
        $status = $request->input('status', 'active');

        $suppliers = Supplier::query()->withCount('products')->search($request->input('q'))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('suppliers.index', compact('suppliers'));
    }

    /** Supplier profile: balance, open bills, recent payments and the account statement. */
    public function show(Request $request, Supplier $supplier): View
    {
        $this->authorize('suppliers.view');
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $accounts = app(SupplierAccountService::class);
        $bills = Purchase::query()->where('supplier_id', $supplier->id)->owing()->get();

        return view('suppliers.show', [
            'supplier' => $supplier,
            'bills' => $bills,
            'owed' => $bills->reduce(fn ($c, $b) => Decimal::add($c, (string) $b->due_amount, 2), '0.00'),
            'advance' => $accounts->advanceFor($supplier->id),
            'purchaseTotal' => (string) Purchase::query()->where('supplier_id', $supplier->id)->where('status', Purchase::RECEIVED)->sum('grand_total'),
            'paidTotal' => (string) SupplierPayment::query()->where('supplier_id', $supplier->id)->where('status', 'posted')->sum('amount'),
            'recentPayments' => SupplierPayment::query()->with('method:id,name')->where('supplier_id', $supplier->id)->orderByDesc('payment_date')->orderByDesc('id')->limit(8)->get(),
            'recentPurchases' => Purchase::query()->where('supplier_id', $supplier->id)->orderByDesc('purchase_date')->orderByDesc('id')->limit(8)->get(),
            'statement' => $accounts->statement($supplier->id, $range['from'] ?? null, $range['to'] ?? null),
            'from' => $range['from'] ?? null,
            'to' => $range['to'] ?? null,
        ]);
    }

    public function statement(Request $request, Supplier $supplier): View
    {
        $this->authorize('suppliers.view');
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return view('suppliers.statement', [
            'supplier' => $supplier,
            'statement' => app(SupplierAccountService::class)->statement($supplier->id, $range['from'] ?? null, $range['to'] ?? null),
            'from' => $range['from'] ?? null,
            'to' => $range['to'] ?? null,
        ]);
    }

    public function adjustBalance(Request $request, Supplier $supplier, AdjustSupplierBalance $adjust): RedirectResponse
    {
        $this->authorize('suppliers.adjust_balance');
        $d = $request->validate([
            'type' => ['required', Rule::in(AdjustSupplierBalance::TYPES)],
            'direction' => ['required', 'in:we_owe,they_owe'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:200'],
        ], ['decimal' => 'Too many decimal places.']);

        try {
            $adjust->execute($supplier, $d['type'], $d['direction'], (string) $d['amount'], $d['reason'], $request->user(), $d['entry_date'] ?? null);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('suppliers.show', $supplier)->with('success', 'The supplier\'s account has been updated.');
    }

    public function create(): View
    {
        $this->authorize('suppliers.create');

        return view('suppliers.form', ['supplier' => new Supplier(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('suppliers.create');
        $data = $this->validated($request);

        $supplier = DB::transaction(function () use ($data, $request) {
            $supplier = Supplier::query()->create($data + [
                'code' => $this->numbers->next('supplier'),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            $this->audit->log('supplier_created', 'suppliers', "Supplier {$supplier->code} · {$supplier->name} created", $supplier, new: $data);

            return $supplier;
        });

        return redirect()->route('suppliers.edit', $supplier)->with('success', "Supplier {$supplier->code} added.");
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorize('suppliers.edit');

        return view('suppliers.form', ['supplier' => $supplier->loadCount('products')]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('suppliers.edit');
        $data = $this->validated($request);

        $old = $supplier->only(array_keys($data));
        $supplier->fill($data + ['updated_by' => $request->user()->id])->save();

        $changed = array_filter($data, fn ($v, $k) => (string) ($old[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $this->audit->log('supplier_updated', 'suppliers', "Supplier {$supplier->code} · {$supplier->name} updated", $supplier, array_intersect_key($old, $changed), $changed);
        }

        return redirect()->route('suppliers.edit', $supplier)->with('success', 'Supplier saved.');
    }

    public function toggle(Supplier $supplier): RedirectResponse
    {
        $this->authorize('suppliers.edit');
        $supplier->forceFill(['is_active' => ! $supplier->is_active])->save();
        $this->audit->log($supplier->is_active ? 'supplier_activated' : 'supplier_deactivated', 'suppliers', "Supplier {$supplier->code} ".($supplier->is_active ? 'activated' : 'deactivated'), $supplier);

        return back()->with('success', $supplier->is_active ? 'Supplier is active again.' : 'Supplier deactivated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('suppliers.delete');

        if ($supplier->hasHistory()) {
            return back()->with('error', 'This supplier is used by products or documents, so it cannot be deleted. Deactivate it instead.');
        }

        $this->audit->log('supplier_deleted', 'suppliers', "Supplier {$supplier->code} · {$supplier->name} deleted", $supplier, $supplier->only(['code', 'name']));
        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'ntn' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $d['is_active'] = $request->boolean('is_active', true);

        return $d;
    }
}
