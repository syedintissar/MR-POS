<x-layouts.app title="Purchases">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Number or supplier invoice" class="input w-52"></div>
            <div><label class="label">Supplier</label>
                <select name="supplier_id" class="input w-48"><option value="">All</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) request('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div><label class="label">Status</label>
                <select name="status" class="input w-32">@foreach(['all' => 'All', 'draft' => 'Drafts', 'received' => 'Received', 'voided' => 'Cancelled'] as $k => $v)<option value="{{ $k }}" @selected(request('status', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label">Payment</label>
                <select name="payment" class="input w-32">@foreach(['all' => 'All', 'unpaid' => 'Unpaid', 'partial' => 'Part paid', 'paid' => 'Paid'] as $k => $v)<option value="{{ $k }}" @selected(request('payment', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label">From</label><input type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label">To</label><input type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        <div class="flex gap-2">
            @can('supplier_payments.view')<a href="{{ route('supplier-payments.index') }}" class="btn btn-secondary">Supplier payments</a>@endcan
            @can('purchases.create')<a href="{{ route('purchases.create') }}" class="btn btn-primary">+ New purchase</a>@endcan
        </div>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Received bills listed</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ number_format((int) $totals->bills) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total of those bills</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ \App\Support\Money::withSymbol($totals->total) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Still to pay on them</div><div class="mt-1 text-2xl font-semibold {{ (float) $totals->due > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ \App\Support\Money::withSymbol($totals->due) }}</div></div>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Number</th><th>Date</th><th>Supplier</th><th>Their invoice</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($purchases as $p)
                <tr>
                    <td class="font-mono text-xs font-medium"><a href="{{ route('purchases.show', $p) }}">{{ $p->label() }}</a></td>
                    <td class="whitespace-nowrap text-slate-600">{{ $p->purchase_date->format('d M Y') }}</td>
                    <td>@can('suppliers.view')<a href="{{ route('suppliers.show', $p->supplier_id) }}" class="text-slate-900">{{ $p->supplier->name }}</a>@else{{ $p->supplier->name }}@endcan</td>
                    <td class="text-slate-600">{{ $p->supplier_invoice_no }}</td>
                    <td class="text-right">{{ \App\Support\Money::format($p->grand_total) }}</td>
                    <td class="text-right text-slate-600">{{ $p->isReceived() ? \App\Support\Money::format($p->paid_amount) : '' }}</td>
                    <td class="text-right font-medium {{ $p->isReceived() && (float) $p->due_amount > 0 ? 'text-amber-700' : 'text-slate-400' }}">{{ $p->isReceived() ? \App\Support\Money::format($p->due_amount) : '' }}</td>
                    <td>
                        @if($p->isDraft())<span class="badge badge-gray">Draft</span>
                        @elseif($p->isVoided())<span class="badge badge-red">Cancelled</span>
                        @elseif($p->payment_status === 'paid')<span class="badge badge-green">Paid</span>
                        @elseif($p->payment_status === 'partial')<span class="badge badge-amber">Part paid</span>
                        @else<span class="badge badge-blue">Unpaid</span>@endif
                    </td>
                    <td class="text-right"><a href="{{ route('purchases.show', $p) }}" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-12 text-center text-slate-500">No purchases found. @can('purchases.create')<a href="{{ route('purchases.create') }}">Record the first one</a>.@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $purchases->links() }}</div>
</x-layouts.app>
