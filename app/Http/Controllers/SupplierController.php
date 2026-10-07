<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Supplier master data. What the shop owes a supplier is posted through the supplier ledger,
 * which arrives with purchases in Phase 4, so balances are not editable here.
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
