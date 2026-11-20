@php use App\Support\Money; @endphp
<x-layouts.app title="Cheques">
    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cheques received, still pending</div>
            <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($pendingReceived) }}</div><div class="text-xs text-slate-500">to be paid into the bank, or waiting for their date</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cheques given, still pending</div>
            <div class="mt-1 text-2xl font-semibold {{ (float) $pendingGiven > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ Money::withSymbol($pendingGiven) }}</div><div class="text-xs text-slate-500">keep this much in the bank for when they are presented</div></div>
    </div>

    @if($canChange)
        <details class="card mb-4" @if($errors->any()) open @endif>
            <summary class="cursor-pointer px-5 py-3 text-sm font-semibold text-slate-800">+ Add a cheque to the register</summary>
            <form method="POST" action="{{ route('cheques.store') }}" class="grid gap-4 border-t border-slate-100 p-5 sm:grid-cols-4" x-data="{ direction: '{{ old('direction', 'received') }}' }">
                @csrf
                <div><label class="label" for="direction">Cheque</label>
                    <select id="direction" name="direction" x-model="direction" class="input"><option value="received">Received from a customer</option><option value="given">Given to a supplier</option></select></div>
                <div x-show="direction === 'received'"><label class="label" for="customer_id">Customer</label>
                    <select id="customer_id" name="customer_id" class="input"><option value="">— or type the name —</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected((int) old('customer_id') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                <div x-show="direction === 'given'" x-cloak><label class="label" for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id" class="input"><option value="">— or type the name —</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                <x-form.input name="party_name" label="Name (if not in the list)" />
                <x-form.input name="amount" label="Amount" type="number" step="0.01" min="0.01" required />
                <x-form.input name="cheque_no" label="Cheque number" />
                <x-form.input name="bank_name" label="Bank" />
                <x-form.input name="cheque_date" label="Date on the cheque" type="date" :value="now()->toDateString()" required help="A post-dated cheque: the future date written on it." />
                <x-form.input name="notes" label="Note" />
                <div class="sm:col-span-4"><button class="btn btn-primary" data-loading-text="Saving…">Add cheque</button>
                    <span class="ml-2 text-xs text-slate-500">A payment recorded with the “Cheque” method adds its cheque here by itself. The register records no money.</span></div>
            </form>
        </details>
    @endif

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <div><label class="label" for="q">Find</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="Name, cheque no. or bank" class="input w-52"></div>
        <div><label class="label" for="f_direction">Which</label>
            <select id="f_direction" name="direction" class="input w-36">@foreach(['all' => 'All', 'received' => 'Received', 'given' => 'Given'] as $k => $v)<option value="{{ $k }}" @selected(request('direction', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
        <div><label class="label" for="f_status">Status</label>
            <select id="f_status" name="status" class="input w-36">@foreach(['pending' => 'Pending', 'cleared' => 'Cleared', 'bounced' => 'Bounced', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select></div>
        <div><label class="label" for="from">Dated from</label><input id="from" type="date" name="from" value="{{ request('from') }}" class="input w-40"></div>
        <div><label class="label" for="to">to</label><input id="to" type="date" name="to" value="{{ request('to') }}" class="input w-40"></div>
        <button class="btn btn-secondary" data-no-loading>Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Date on cheque</th><th></th><th>From / to</th><th>Cheque</th><th class="text-right">Amount</th><th>Status</th><th>Payment</th><th></th></tr></thead>
            <tbody>
            @forelse($cheques as $c)
                <tr class="{{ in_array($c->status, ['cancelled'], true) ? 'text-slate-400' : '' }}">
                    <td class="whitespace-nowrap">{{ $c->cheque_date->format('d M Y') }}
                        @if($c->isPending())
                            @if($c->cheque_date->isFuture())<div class="text-xs text-slate-500">post-dated</div>@elseif(! $c->cheque_date->isToday())<div class="text-xs font-medium text-red-600">date has passed</div>@else<div class="text-xs font-medium text-amber-600">today</div>@endif
                        @endif</td>
                    <td>@if($c->direction === 'received')<span class="badge badge-green">received</span>@else<span class="badge badge-amber">given</span>@endif</td>
                    <td>{{ $c->party_name }}@if($c->notes)<div class="text-xs text-slate-500">{{ $c->notes }}</div>@endif</td>
                    <td>{{ $c->cheque_no ?: '—' }}@if($c->bank_name)<div class="text-xs text-slate-500">{{ $c->bank_name }}</div>@endif</td>
                    <td class="text-right font-medium">{{ Money::format($c->amount) }}</td>
                    <td>
                        @if($c->status === 'pending')<span class="badge badge-blue">Pending</span>
                        @elseif($c->status === 'cleared')<span class="badge badge-green">Cleared</span>
                        @elseif($c->status === 'bounced')<span class="badge badge-red">Bounced</span>
                        @else<span class="badge badge-gray">Cancelled</span>@endif
                        @if($c->status_date)<div class="text-xs text-slate-500">{{ $c->status_date->format('d M Y') }}</div>@endif
                    </td>
                    <td class="text-xs">
                        @if($c->customerPayment)@can('customer_payments.view')<a href="{{ route('customer-payments.show', $c->customer_payment_id) }}">{{ $c->customerPayment->receipt_no }}</a>@else{{ $c->customerPayment->receipt_no }}@endcan
                        @elseif($c->supplierPayment)@can('supplier_payments.view')<a href="{{ route('supplier-payments.show', $c->supplier_payment_id) }}">{{ $c->supplierPayment->payment_no }}</a>@else{{ $c->supplierPayment->payment_no }}@endcan
                        @else<span class="text-slate-400">not recorded yet</span>@endif
                    </td>
                    <td class="text-right">
                        @if($canChange)
                            <form method="POST" action="{{ route('cheques.update', $c) }}" class="inline-flex flex-wrap items-center justify-end gap-1">
                                @csrf
                                @if($c->isPending())
                                    <input type="date" name="cheque_date" value="{{ $c->cheque_date->toDateString() }}" class="h-7 rounded border border-slate-300 px-1 text-xs" aria-label="Date on the cheque">
                                    <button name="status" value="pending" class="btn btn-secondary btn-sm" title="Save a new date">Save date</button>
                                    <button name="status" value="cleared" class="btn btn-secondary btn-sm text-emerald-700">Cleared</button>
                                    <button name="status" value="bounced" class="btn btn-secondary btn-sm text-red-600">Bounced</button>
                                    <button name="status" value="cancelled" class="btn btn-secondary btn-sm">Cancel</button>
                                @else
                                    <button name="status" value="pending" class="btn btn-secondary btn-sm" title="Put it back to pending">Undo</button>
                                @endif
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No cheques here.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $cheques->links() }}</div>
</x-layouts.app>
