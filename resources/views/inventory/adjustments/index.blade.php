<x-layouts.app title="Stock adjustments">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Reason</label>
                <select name="reason" class="input w-56"><option value="">All</option>@foreach(\App\Models\StockAdjustment::REASONS as $k => $v)<option value="{{ $k }}" @selected(request('reason') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label">Status</label>
                <select name="status" class="input w-32">@foreach(['all' => 'All', 'posted' => 'Posted', 'voided' => 'Cancelled'] as $k => $v)<option value="{{ $k }}" @selected(request('status', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        <div class="flex gap-2">
            <a href="{{ route('inventory.stock') }}" class="btn btn-secondary">Stock</a>
            @can('inventory.adjust')<a href="{{ route('adjustments.create') }}" class="btn btn-primary">+ Adjust stock</a>@endcan
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Number</th><th>Date</th><th>Reason</th><th class="text-right">Products</th><th>By</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($adjustments as $a)
                <tr>
                    <td class="font-mono text-xs font-medium"><a href="{{ route('adjustments.show', $a) }}">{{ $a->adjustment_no }}</a></td>
                    <td class="whitespace-nowrap text-slate-600">{{ $a->adjustment_date->format('d M Y') }}</td>
                    <td>{{ $a->reasonLabel() }}@if($a->notes)<div class="max-w-sm truncate text-xs text-slate-500">{{ $a->notes }}</div>@endif</td>
                    <td class="text-right">{{ $a->items_count }}</td>
                    <td class="text-slate-600">{{ $a->creator?->name }}</td>
                    <td>@if($a->isVoided())<span class="badge badge-red">Cancelled</span>@else<span class="badge badge-green">Posted</span>@endif</td>
                    <td class="text-right"><a href="{{ route('adjustments.show', $a) }}" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">No adjustments yet. Use <em>Adjust stock</em> to record your opening stock, a stock count, or damaged and lost items.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $adjustments->links() }}</div>
</x-layouts.app>
