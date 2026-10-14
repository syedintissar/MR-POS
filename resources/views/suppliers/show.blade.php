@php
    use App\Models\SupplierLedgerEntry;
    use App\Support\Money;
    $balance = (string) $supplier->balance;
@endphp
<x-layouts.app :title="$supplier->name">
    <div class="mx-auto max-w-6xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $supplier->code }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $supplier->name }}@if($supplier->company) <span class="text-base font-normal text-slate-500">· {{ $supplier->company }}</span>@endif</h2>
                <div class="mt-1 text-sm text-slate-500">
                    {{ collect([$supplier->contact_person, $supplier->phone, $supplier->email])->filter()->implode(' · ') }}
                    @unless($supplier->is_active) <span class="badge badge-gray">Inactive</span>@endunless
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('suppliers.edit')<a href="{{ route('suppliers.edit', $supplier) }}" class="btn btn-secondary btn-sm">Edit details</a>@endcan
                @can('purchases.create')<a href="{{ route('purchases.create') }}" class="btn btn-secondary btn-sm">New purchase</a>@endcan
                @can('supplier_payments.create')<a href="{{ route('supplier-payments.create', ['supplier' => $supplier->id]) }}" class="btn btn-primary btn-sm">Pay this supplier</a>@endcan
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card card-body">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account balance</div>
                <div class="mt-1 text-2xl font-semibold {{ (float) $balance > 0 ? 'text-amber-600' : ((float) $balance < 0 ? 'text-blue-700' : 'text-slate-900') }}">{{ Money::withSymbol(abs((float) $balance)) }}</div>
                <div class="text-xs text-slate-500">{{ (float) $balance > 0 ? 'you owe this supplier' : ((float) $balance < 0 ? 'this supplier holds an advance of yours' : 'nothing owed either way') }}</div>
            </div>
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Open bills</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ $bills->count() }}</div><div class="text-xs text-slate-500">{{ Money::withSymbol($owed) }} still due</div></div>
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bought in total</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($purchaseTotal) }}</div></div>
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paid in total</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($paidTotal) }}</div>@if((float) $advance > 0)<div class="text-xs text-blue-700">{{ Money::format($advance) }} not yet applied to a bill</div>@endif</div>
        </div>

        @php $creditTotal = array_reduce($creditSources, fn ($c, $s) => bcadd($c, $s['left'], 2), '0.00'); @endphp
        @if((float) $creditTotal > 0 || $creditUses->isNotEmpty())
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Supplier credit</h2>
                    @can('supplier_payments.create')
                        @if((float) $creditTotal > 0 && $bills->isNotEmpty())
                            <form method="POST" action="{{ route('suppliers.use-credit', $supplier) }}" data-confirm="Use {{ Money::withSymbol($creditTotal) }} of {{ $supplier->name }}'s credit on the open bills, oldest first?" data-confirm-label="Use credit">@csrf<button class="btn btn-primary btn-sm">Use supplier credit</button></form>
                        @endif
                    @endcan
                </div>
                <div class="card-body space-y-3 text-sm">
                    <p class="text-slate-600">
                        @if((float) $creditTotal > 0)<strong>{{ Money::withSymbol($creditTotal) }}</strong> of credit is held for you ({{ collect($creditSources)->map(fn ($s) => $s['return_no'].': '.Money::format($s['left']))->implode(' · ') }}).
                            @if($bills->isEmpty()) It can be used as soon as there is a bill to pay. @else Pressing the button takes it off the open bills, oldest first, and it can never be used twice. @endif
                        @else None of the credit is left; every use is listed below. @endif
                    </p>
                    @if($creditUses->isNotEmpty())
                        <div class="overflow-x-auto"><table class="table">
                            <thead><tr><th>Date</th><th>Credit from</th><th>Used on</th><th class="text-right">Amount</th><th>By</th><th></th></tr></thead>
                            <tbody>
                            @foreach($creditUses as $u)
                                <tr class="{{ $u->isVoided() ? 'text-slate-400 line-through' : '' }}">
                                    <td class="whitespace-nowrap">{{ $u->created_at->format('d M Y, H:i') }}</td>
                                    <td class="font-mono text-xs">{{ $u->purchaseReturn->return_no }}</td>
                                    <td class="font-mono text-xs">{{ $u->purchase->purchase_no }}</td>
                                    <td class="text-right">{{ Money::format($u->amount) }}</td>
                                    <td>{{ $u->creator?->name }}</td>
                                    <td class="text-right no-underline">
                                        @if($u->isVoided())<span class="badge badge-red">cancelled</span>
                                        @else
                                            @can('supplier_payments.void')
                                                <form method="POST" action="{{ route('supplier-credit.void', $u) }}" class="flex items-center justify-end gap-1" data-confirm="Take this use of credit back? The bill will owe the amount again." data-confirm-label="Take back" data-danger="1">
                                                    @csrf<input name="reason" required placeholder="Reason" class="input w-32 py-1 text-xs" aria-label="Reason for taking this use of credit back"><button class="btn btn-secondary btn-sm">Take back</button>
                                                </form>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </div>
            </div>
        @endif

        @if($bills->isNotEmpty())
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Bills still to pay</h2></div>
                <table class="table">
                    <thead><tr><th>Bill</th><th>Date</th><th>Their invoice</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th></th></tr></thead>
                    <tbody>
                    @foreach($bills as $b)
                        <tr>
                            <td class="font-mono text-xs font-medium">@can('purchases.view')<a href="{{ route('purchases.show', $b) }}">{{ $b->purchase_no }}</a>@else{{ $b->purchase_no }}@endcan</td>
                            <td class="whitespace-nowrap">{{ $b->purchase_date->format('d M Y') }}</td><td>{{ $b->supplier_invoice_no }}</td>
                            <td class="text-right">{{ Money::format($b->grand_total) }}</td><td class="text-right text-slate-500">{{ Money::format($b->paid_amount) }}</td>
                            <td class="text-right font-medium text-amber-700">{{ Money::format($b->due_amount) }}</td>
                            <td class="text-right">@can('supplier_payments.create')<a href="{{ route('supplier-payments.create', ['supplier' => $supplier->id, 'purchase' => $b->id]) }}" class="btn btn-secondary btn-sm">Pay</a>@endcan</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Recent purchases</h2>@can('purchases.view')<a href="{{ route('purchases.index', ['supplier_id' => $supplier->id]) }}" class="text-xs">all</a>@endcan</div>
                <table class="table"><tbody>
                @forelse($recentPurchases as $p)
                    <tr><td class="font-mono text-xs">@can('purchases.view')<a href="{{ route('purchases.show', $p) }}">{{ $p->label() }}</a>@else{{ $p->label() }}@endcan</td>
                        <td class="whitespace-nowrap">{{ $p->purchase_date->format('d M Y') }}</td>
                        <td class="text-right">{{ Money::format($p->grand_total) }}</td>
                        <td>@if($p->isDraft())<span class="badge badge-gray">Draft</span>@elseif($p->isVoided())<span class="badge badge-red">Cancelled</span>@elseif($p->payment_status === 'paid')<span class="badge badge-green">Paid</span>@else<span class="badge badge-amber">{{ $p->payment_status === 'partial' ? 'Part paid' : 'Unpaid' }}</span>@endif</td></tr>
                @empty<tr><td class="px-4 py-6 text-center text-slate-500">No purchases yet.</td></tr>@endforelse
                </tbody></table>
            </div>
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Recent payments</h2>@can('supplier_payments.view')<a href="{{ route('supplier-payments.index', ['supplier_id' => $supplier->id]) }}" class="text-xs">all</a>@endcan</div>
                <table class="table"><tbody>
                @forelse($recentPayments as $p)
                    <tr class="{{ $p->isVoided() ? 'text-slate-400 line-through' : '' }}"><td class="font-mono text-xs">@can('supplier_payments.view')<a href="{{ route('supplier-payments.show', $p) }}">{{ $p->payment_no }}</a>@else{{ $p->payment_no }}@endcan</td>
                        <td class="whitespace-nowrap">{{ $p->payment_date->format('d M Y') }}</td><td>{{ $p->method->name }}</td><td class="text-right">{{ Money::format($p->amount) }}</td></tr>
                @empty<tr><td class="px-4 py-6 text-center text-slate-500">No payments yet.</td></tr>@endforelse
                </tbody></table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Account statement</h2>
                <a href="{{ route('suppliers.statement', array_filter(['supplier' => $supplier->id, 'from' => $from, 'to' => $to])) }}" target="_blank" class="btn btn-secondary btn-sm">Print</a>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2 border-b border-slate-200 px-5 py-3">
                <div><label class="label">From</label><input type="date" name="from" value="{{ $from }}" class="input w-40"></div>
                <div><label class="label">To</label><input type="date" name="to" value="{{ $to }}" class="input w-40"></div>
                <button class="btn btn-secondary" data-no-loading>Show</button>
                @if($from || $to)<a href="{{ route('suppliers.show', $supplier) }}" class="btn btn-secondary">Whole history</a>@endif
            </form>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Date</th><th>What</th><th class="text-right">Paid / returned (−)</th><th class="text-right">Bought (+)</th><th class="text-right">Balance</th></tr></thead>
                    <tbody>
                    @if($from)<tr class="bg-slate-50"><td colspan="4" class="text-slate-600">Brought forward before {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}</td><td class="text-right font-medium">{{ Money::format($statement['opening']) }}</td></tr>@endif
                    @forelse($statement['rows'] as $row)
                        @php($e = $row['entry'])
                        <tr>
                            <td class="whitespace-nowrap text-slate-600">{{ $e->entry_date->format('d M Y') }}</td>
                            <td>
                                <span class="font-medium text-slate-800">{{ SupplierLedgerEntry::label($e->entry_type) }}</span>
                                <div class="text-xs text-slate-500">
                                    @if($e->source_type === 'purchase' && auth()->user()->can('purchases.view'))<a href="{{ route('purchases.show', $e->source_id) }}">{{ $e->description }}</a>
                                    @elseif($e->source_type === 'supplier_payment' && auth()->user()->can('supplier_payments.view'))<a href="{{ route('supplier-payments.show', $e->source_id) }}">{{ $e->description }}</a>
                                    @else{{ $e->description }}@endif
                                </div>
                            </td>
                            <td class="text-right text-emerald-700">{{ (float) $e->debit ? Money::format($e->debit) : '' }}</td>
                            <td class="text-right">{{ (float) $e->credit ? Money::format($e->credit) : '' }}</td>
                            <td class="text-right font-medium {{ (float) $row['balance'] < 0 ? 'text-blue-700' : '' }}">{{ Money::format($row['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No entries{{ $from || $to ? ' in this period' : ' yet' }}.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr class="bg-slate-50 font-semibold"><td colspan="2">Total for the period · closing balance</td><td class="px-4 py-2 text-right">{{ Money::format($statement['debit']) }}</td><td class="px-4 py-2 text-right">{{ Money::format($statement['credit']) }}</td><td class="px-4 py-2 text-right">{{ Money::format($statement['closing']) }}</td></tr></tfoot>
                </table>
            </div>
            <p class="border-t border-slate-200 px-5 py-2 text-xs text-slate-500">A positive balance is what you owe the supplier; a negative balance (blue) is money of yours they are holding.</p>
        </div>

        @can('suppliers.adjust_balance')
            <details class="card card-body" @if($errors->hasAny(['type', 'direction', 'amount', 'reason', 'entry_date'])) open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-slate-800">Set an opening balance or correct the account (administrator only)</summary>
                <form method="POST" action="{{ route('suppliers.adjust-balance', $supplier) }}" class="mt-4 grid gap-4 sm:grid-cols-4"
                      data-confirm="Post this entry to {{ $supplier->name }}'s account? It cannot be edited afterwards, only offset by another entry." data-confirm-label="Post entry">
                    @csrf
                    <div><label class="label" for="type">Type</label>
                        <select id="type" name="type" class="input">
                            <option value="opening_balance" @selected(old('type') === 'opening_balance')>Opening balance (once)</option>
                            <option value="balance_adjustment" @selected(old('type', 'balance_adjustment') === 'balance_adjustment')>Correction</option>
                        </select></div>
                    <div><label class="label" for="direction">Who owes whom</label>
                        <select id="direction" name="direction" class="input">
                            <option value="we_owe" @selected(old('direction') === 'we_owe')>We owe the supplier</option>
                            <option value="they_owe" @selected(old('direction') === 'they_owe')>The supplier owes us</option>
                        </select></div>
                    <div><label class="label" for="amount">Amount</label><input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required class="input">
                        @error('amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                    <x-form.input name="entry_date" label="Date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" />
                    <x-form.input name="reason" label="Reason (required)" required class="sm:col-span-3" help="For example: balance carried over from the paper register." />
                    <div class="flex items-end"><button class="btn btn-primary">Post entry</button></div>
                </form>
            </details>
        @endcan

        <a href="{{ route('suppliers.index') }}" class="inline-block text-sm">← All suppliers</a>
    </div>
</x-layouts.app>
