@php use App\Support\Money; @endphp
<x-layouts.app title="Sales returns">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="q">Find</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="SRN-000123, invoice, customer" class="input w-56"></div>
            <div><label class="label" for="status">Status</label>
                <select id="status" name="status" class="input w-32">@foreach(['posted' => 'Recorded', 'voided' => 'Cancelled', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label" for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label" for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        @can('sale_returns.create')<a href="{{ route('sale-returns.create') }}" class="btn btn-primary">Take goods back</a>@endcan
    </div>

    <div class="mb-4 card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Value returned in this list</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($total) }}</div></div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>No.</th><th>Date</th><th>Invoice</th><th>Customer</th><th>Reason</th><th class="text-right">Value</th><th class="text-right">Money back</th><th class="text-right">Store credit</th><th></th></tr></thead>
            <tbody>
            @forelse($returns as $r)
                <tr class="{{ $r->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('sale-returns.show', $r) }}">{{ $r->return_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $r->return_date->format('d M Y, H:i') }}</td>
                    <td class="font-mono text-xs"><a href="{{ route('sales.show', $r->sale_id) }}">{{ $r->sale->invoice_no }}</a></td>
                    <td>{{ $r->customer->is_walk_in ? 'Walk-in customer' : $r->customer->name }}</td>
                    <td class="max-w-xs truncate">{{ $r->reason }}</td>
                    <td class="text-right font-medium {{ $r->isVoided() ? 'line-through' : '' }}">{{ Money::format($r->total) }}</td>
                    <td class="text-right">{{ (float) $r->refund_amount ? Money::format($r->refund_amount) : '' }}</td>
                    <td class="text-right">{{ (float) $r->store_credit_amount ? Money::format($r->store_credit_amount) : '' }}</td>
                    <td class="text-right">@if($r->isVoided())<span class="badge badge-red">Cancelled</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-12 text-center text-slate-500">No returns found. @can('sale_returns.create')<a href="{{ route('sale-returns.create') }}">Take goods back.</a>@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $returns->links() }}</div>
</x-layouts.app>
