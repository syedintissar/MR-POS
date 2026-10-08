<x-layouts.app title="Sales">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Invoice</label><input type="search" name="q" value="{{ request('q') }}" placeholder="INV-000123" class="input w-40"></div>
            @unless($ownOnly)
                <div><label class="label">Cashier</label>
                    <select name="cashier" class="input w-44"><option value="">All</option>@foreach($cashiers as $c)<option value="{{ $c->id }}" @selected((int) request('cashier') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            @endunless
            <div><label class="label">Paid by</label>
                <select name="method" class="input w-40"><option value="">Any</option>@foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) request('method') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
            <div><label class="label">Status</label>
                <select name="status" class="input w-32">@foreach(['all' => 'All', 'completed' => 'Completed', 'voided' => 'Cancelled'] as $k => $v)<option value="{{ $k }}" @selected(request('status', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label">From</label><input type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label">To</label><input type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        @can('pos.access')<a href="{{ route('pos.index') }}" class="btn btn-primary">Open the till (POS)</a>@endcan
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Completed sales listed</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ number_format((int) $totals->sales) }}</div>@if($ownOnly)<div class="text-xs text-slate-500">your own sales</div>@endif</div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total of those sales</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ \App\Support\Money::withSymbol($totals->total) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">of which tax</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ \App\Support\Money::withSymbol($totals->tax) }}</div></div>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Cashier</th><th class="text-right">Items</th><th class="text-right">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($sales as $s)
                <tr class="{{ $s->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('sales.show', $s) }}">{{ $s->invoice_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $s->sale_date->format('d M Y, H:i') }}</td>
                    <td>{{ $s->customer->is_walk_in ? 'Walk-in' : $s->customer->name }}</td>
                    <td>{{ $s->cashier?->name }}</td>
                    <td class="text-right">{{ $s->items_count }}</td>
                    <td class="text-right font-medium {{ $s->isVoided() ? 'line-through' : '' }}">{{ \App\Support\Money::format($s->grand_total) }}</td>
                    <td>@if($s->isVoided())<span class="badge badge-red">Cancelled</span>@elseif($s->payment_status === 'paid')<span class="badge badge-green">Paid</span>@else<span class="badge badge-amber">{{ $s->payment_status === 'partial' ? 'Part paid' : 'Unpaid' }}</span>@endif</td>
                    <td class="text-right"><a href="{{ route('sales.show', $s) }}" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No sales found. @can('pos.access')<a href="{{ route('pos.index') }}">Open the till</a> to make the first one.@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $sales->links() }}</div>
</x-layouts.app>
