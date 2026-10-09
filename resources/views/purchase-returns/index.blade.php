@php use App\Support\Money; @endphp
<x-layouts.app title="Purchase returns">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="q">Find</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="PRN-000123, purchase, supplier" class="input w-52"></div>
            <div><label class="label" for="supplier_id">Supplier</label>
                <select id="supplier_id" name="supplier_id" class="input w-44"><option value="">All</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) request('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div><label class="label" for="status">Status</label>
                <select id="status" name="status" class="input w-32">@foreach(['posted' => 'Recorded', 'voided' => 'Cancelled', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label" for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label" for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        @can('purchase_returns.create')<a href="{{ route('purchase-returns.create') }}" class="btn btn-primary">Send goods back</a>@endcan
    </div>

    <div class="mb-4 card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Credit from suppliers in this list</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($total) }}</div></div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>No.</th><th>Date</th><th>Supplier</th><th>Purchase</th><th>Reason</th><th class="text-right">Credit</th><th>Settled</th><th></th></tr></thead>
            <tbody>
            @forelse($returns as $r)
                <tr class="{{ $r->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('purchase-returns.show', $r) }}">{{ $r->return_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $r->return_date->format('d M Y, H:i') }}</td>
                    <td>{{ $r->supplier->name }}</td>
                    <td class="font-mono text-xs">@if($r->purchase)<a href="{{ route('purchases.show', $r->purchase_id) }}">{{ $r->purchase->purchase_no }}</a>@else<span class="text-slate-400">no bill</span>@endif</td>
                    <td class="max-w-xs truncate">{{ $r->reason }}</td>
                    <td class="text-right font-medium {{ $r->isVoided() ? 'line-through' : '' }}">{{ Money::format($r->total) }}</td>
                    <td>{{ $r->settlement === 'refund' ? 'money received' : 'on account' }}</td>
                    <td class="text-right">@if($r->isVoided())<span class="badge badge-red">Cancelled</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No returns found. @can('purchase_returns.create')<a href="{{ route('purchase-returns.create') }}">Send goods back.</a>@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $returns->links() }}</div>
</x-layouts.app>
