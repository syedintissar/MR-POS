<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The cheque register. It follows each cheque (the date on it, cleared, bounced or cancelled) and reminds when one is due.
 * It records no money: that is the job of the customer or supplier payment the cheque came with, which the register links to.
 */
class ChequeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('cashbook.view');
        $request->validate([
            'direction' => ['nullable', 'in:all,received,given'], 'status' => ['nullable', Rule::in(['all', ...Cheque::STATUSES])],
            'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $status = $request->input('status', 'pending');

        $query = Cheque::query()
            ->when(in_array($request->input('direction'), ['received', 'given'], true), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('cheque_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('cheque_date', '<=', $request->input('to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $request->input('q'))).'%';
                $q->where(fn ($w) => $w->where('party_name', 'like', $like)->orWhere('cheque_no', 'like', $like)->orWhere('bank_name', 'like', $like));
            });

        $pending = Cheque::query()->where('status', Cheque::PENDING)->toBase()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'received' THEN amount ELSE 0 END), 0) AS received, COALESCE(SUM(CASE WHEN direction = 'given' THEN amount ELSE 0 END), 0) AS given")->first();

        return view('cheques.index', [
            'cheques' => $query->with(['customerPayment:id,receipt_no', 'supplierPayment:id,payment_no'])->orderBy('cheque_date')->orderBy('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'pendingReceived' => (string) $pending->received,
            'pendingGiven' => (string) $pending->given,
            'customers' => Customer::query()->where('is_walk_in', false)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canChange' => $request->user()->can('cashbook.transact'),
        ]);
    }

    /** A cheque written into the register by hand (a post-dated cheque taken or given before any payment is recorded). */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('cashbook.transact');
        $d = $request->validate([
            'direction' => ['required', 'in:received,given'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('is_walk_in', false)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')],
            'party_name' => ['nullable', 'string', 'max:150'],
            'cheque_no' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'cheque_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['decimal' => 'Too many decimal places.']);

        $received = $d['direction'] === Cheque::RECEIVED;
        $customer = $received && ! empty($d['customer_id']) ? Customer::query()->find((int) $d['customer_id']) : null;
        $supplier = ! $received && ! empty($d['supplier_id']) ? Supplier::query()->find((int) $d['supplier_id']) : null;
        $name = trim((string) ($d['party_name'] ?? '')) ?: (string) ($customer?->name ?? $supplier?->name ?? '');
        if ($name === '') {
            return back()->withInput()->withErrors(['party_name' => $received ? 'Choose the customer or type who the cheque is from.' : 'Choose the supplier or type who the cheque was given to.']);
        }

        $cheque = Cheque::query()->create([
            'direction' => $d['direction'], 'customer_id' => $customer?->id, 'supplier_id' => $supplier?->id, 'party_name' => $name,
            'cheque_no' => trim((string) ($d['cheque_no'] ?? '')) ?: null, 'bank_name' => trim((string) ($d['bank_name'] ?? '')) ?: null,
            'amount' => Decimal::round((string) $d['amount'], 2), 'cheque_date' => $d['cheque_date'], 'status' => Cheque::PENDING,
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('cheque_recorded', 'cashbook', "Cheque {$cheque->direction}: {$cheque->amount} ".($received ? 'from' : 'to')." {$name}, dated ".$cheque->cheque_date->toDateString(), $cheque);

        return redirect()->route('cheques.index')->with('success', 'Cheque added to the register. It records no money: record the payment itself when the cheque is paid in or handed over.');
    }

    /** What became of the cheque (cleared, bounced, cancelled, or pending again), or a new date for it. */
    public function update(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('cashbook.transact');
        $d = $request->validate([
            'status' => ['nullable', Rule::in(Cheque::STATUSES)], 'cheque_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $old = $cheque->only(['status', 'cheque_date']);
        if (! empty($d['status']) && $d['status'] !== $cheque->status) {
            $cheque->status = $d['status'];
            $cheque->status_date = $d['status'] === Cheque::PENDING ? null : now()->toDateString();
        }
        if (! empty($d['cheque_date'])) {
            $cheque->cheque_date = $d['cheque_date'];
        }
        if (array_key_exists('notes', $d) && $d['notes'] !== null) {
            $cheque->notes = trim($d['notes']) ?: null;
        }
        $cheque->updated_by = $request->user()->id;
        $cheque->save();

        $this->audit->log('cheque_updated', 'cashbook', "Cheque of {$cheque->amount} ({$cheque->party_name}) is now {$cheque->status}, dated ".$cheque->cheque_date->toDateString(), $cheque,
            ['status' => $old['status'], 'cheque_date' => (string) $old['cheque_date']?->toDateString()], ['status' => $cheque->status, 'cheque_date' => $cheque->cheque_date->toDateString()]);

        $message = match (true) {
            $cheque->status === 'bounced' && ($cheque->customer_payment_id || $cheque->supplier_payment_id) => 'Marked as bounced. The payment it came with still counts as paid: open it from the register and cancel it so the account shows the money as owed again.',
            $cheque->status === 'bounced' => 'Marked as bounced.',
            default => 'Cheque updated.',
        };

        return back()->with('success', $message);
    }
}
