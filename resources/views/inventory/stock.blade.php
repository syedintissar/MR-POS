@php($canCost = auth()->user()->can('products.view_cost'))
<x-layouts.app title="Stock">
    <div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ route('inventory.stock') }}" class="card card-body block hover:border-brand-500"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Stock items</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ number_format($summary['items']) }}</div></a>
        <a href="{{ route('inventory.stock', array_merge(request()->query(), ['level' => 'low'])) }}" class="card card-body block hover:border-amber-400"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Low stock</div><div class="mt-1 text-2xl font-semibold {{ $summary['low'] ? 'text-amber-600' : 'text-slate-900' }}">{{ number_format($summary['low']) }}</div></a>
        <a href="{{ route('inventory.stock', array_merge(request()->query(), ['level' => 'out'])) }}" class="card card-body block hover:border-red-400"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Out of stock</div><div class="mt-1 text-2xl font-semibold {{ $summary['out'] ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($summary['out']) }}</div></a>
        @if($summary['value'] !== null)
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Stock value (at average cost)</div><div class="mt-1 text-2xl font-semibold text-slate-900">Rs. {{ number_format((float) $summary['value'], 2) }}</div></div>
        @endif
    </div>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Name, SKU, barcode or model" class="input w-60"></div>
            <div><label class="label">Category</label>
                <select name="category" class="input w-44"><option value="">All</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>
                        @foreach($c->children as $ch)<option value="{{ $ch->id }}" @selected(request('category') == $ch->id)>&nbsp;&nbsp;↳ {{ $ch->name }}</option>@endforeach
                    @endforeach</select></div>
            <div><label class="label">Brand</label>
                <select name="brand" class="input w-36"><option value="">All</option>@foreach($brands as $b)<option value="{{ $b->id }}" @selected(request('brand') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
            <div><label class="label">Level</label>
                <select name="level" class="input w-36">@foreach(['all' => 'All', 'low' => 'Low', 'out' => 'Out of stock', 'negative' => 'Below zero', 'ok' => 'Well stocked'] as $k => $v)<option value="{{ $k }}" @selected(request('level', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
            @if(request()->query())<a href="{{ route('inventory.stock') }}" class="btn btn-secondary">Clear</a>@endif
        </form>
        <div class="flex gap-2">
            <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">Adjustments</a>
            @can('inventory.adjust')<a href="{{ route('adjustments.create') }}" class="btn btn-primary">+ Adjust stock</a>@endcan
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>SKU</th><th>Product</th><th>Shelf</th><th class="text-right">Stock</th><th class="text-right">Warn at</th>@if($canCost)<th class="text-right">Value</th>@endif<th></th></tr></thead>
            <tbody>
            @forelse($products as $p)
                @php($stock = (float) $p->current_stock) @php($low = (float) $p->lowStockLevel($threshold))
                <tr>
                    <td class="font-mono text-xs text-slate-600">{{ $p->sku }}</td>
                    <td><a href="{{ route('products.show', $p) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $p->name }}</a>
                        <div class="text-xs text-slate-500">{{ collect([$p->brand?->name, $p->model])->filter()->implode(' · ') }}</div></td>
                    <td class="text-slate-600">{{ $p->rack_location }}</td>
                    <td class="whitespace-nowrap text-right">
                        <span class="{{ $stock <= 0 ? 'font-semibold text-red-600' : ($stock <= $low ? 'font-semibold text-amber-600' : '') }}">{{ \App\Support\Decimal::trim($p->current_stock) }}</span>
                        <span class="text-xs text-slate-400">{{ $p->unit->short_name }}</span>
                        @if($stock < 0)<span class="badge badge-red ml-1">Below zero</span>@elseif($stock == 0)<span class="badge badge-red ml-1">Out</span>@elseif($stock <= $low)<span class="badge badge-amber ml-1">Low</span>@endif
                    </td>
                    <td class="text-right text-slate-500">{{ \App\Support\Decimal::trim($p->lowStockLevel($threshold)) }}</td>
                    @if($canCost)<td class="text-right text-slate-600">{{ $stock > 0 ? number_format($stock * (float) $p->average_cost, 2) : '—' }}</td>@endif
                    <td class="text-right"><a href="{{ route('inventory.movements', ['product' => $p->id]) }}" class="btn btn-secondary btn-sm">Stock card</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">Nothing matches. @if(! request()->query()) Add products first, then record what you have with <em>Adjust stock</em> (reason “Opening stock”).@endif</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</x-layouts.app>
