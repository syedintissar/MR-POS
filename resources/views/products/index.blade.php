<x-layouts.app title="Products">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Name, SKU, barcode or model" class="input w-64" autofocus></div>
            <div><label class="label">Category</label>
                <select name="category" class="input w-48"><option value="">All</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>
                        @foreach($c->children as $ch)<option value="{{ $ch->id }}" @selected(request('category') == $ch->id)>&nbsp;&nbsp;↳ {{ $ch->name }}</option>@endforeach
                    @endforeach
                </select></div>
            <div><label class="label">Brand</label>
                <select name="brand" class="input w-36"><option value="">All</option>
                    @foreach($brands as $b)<option value="{{ $b->id }}" @selected(request('brand') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
            <div><label class="label">Stock</label>
                <select name="stock" class="input w-32">
                    @foreach(['all' => 'All', 'in' => 'In stock', 'low' => 'Low', 'out' => 'Out of stock'] as $k => $v)<option value="{{ $k }}" @selected(request('stock', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label">Show</label>
                <select name="status" class="input w-32">
                    @foreach(['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected(request('status', 'active') === $k)>{{ $v }}</option>@endforeach</select></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
            @if(request()->query())<a href="{{ route('products.index') }}" class="btn btn-secondary">Clear</a>@endif
        </form>
        <div class="flex gap-2">
            @can('products.import')<a href="{{ route('products.import') }}" class="btn btn-secondary">Import</a>@endcan
            @can('products.create')<a href="{{ route('products.create') }}" class="btn btn-primary">+ New product</a>@endcan
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr>
                <th>SKU</th><th>Product</th><th>Category</th><th class="text-right">Stock</th><th class="text-right">Price</th>
                @can('products.view_cost')<th class="text-right">Avg cost</th>@endcan
                <th>Status</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($products as $p)
                @php
                    $isStock = $p->isStockItem();
                    $stock = (float) $p->current_stock;
                    $low = (float) $p->lowStockLevel($threshold);
                @endphp
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs text-slate-600">{{ $p->sku }}</td>
                    <td>
                        <a href="{{ route('products.show', $p) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $p->name }}</a>
                        <div class="text-xs text-slate-500">{{ collect([$p->brand?->name, $p->model])->filter()->implode(' · ') }}@if(! $isStock) <span class="badge badge-blue ml-1">service</span>@endif</div>
                    </td>
                    <td class="text-slate-600">{{ $p->category?->parent ? $p->category->parent->name.' › ' : '' }}{{ $p->category?->name }}</td>
                    <td class="whitespace-nowrap text-right">
                        @if($isStock)
                            <span class="{{ $stock <= 0 ? 'font-semibold text-red-600' : ($stock <= $low ? 'font-semibold text-amber-600' : '') }}">{{ \App\Support\Decimal::trim($p->current_stock) }}</span>
                            <span class="text-xs text-slate-400">{{ $p->unit->short_name }}</span>
                            @if($stock <= 0)<span class="badge badge-red ml-1">Out</span>@elseif($stock <= $low)<span class="badge badge-amber ml-1">Low</span>@endif
                        @else — @endif
                    </td>
                    <td class="whitespace-nowrap text-right">{{ number_format((float) $p->sale_price, 2) }}</td>
                    @can('products.view_cost')<td class="whitespace-nowrap text-right text-slate-600">{{ $isStock ? number_format((float) $p->average_cost, 2) : '—' }}</td>@endcan
                    <td>@if($p->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-gray">Inactive</span>@endif</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('products.show', $p) }}" class="btn btn-secondary btn-sm">View</a>
                        @can('products.edit')<a href="{{ route('products.edit', $p) }}" class="btn btn-secondary btn-sm">Edit</a>@endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">
                    @if(request()->query())No products match these filters.
                    @else No products yet. @can('products.create')<a href="{{ route('products.create') }}">Add the first one</a>@endcan @can('products.import') or <a href="{{ route('products.import') }}">import a list from Excel</a>@endcan.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
</x-layouts.app>
