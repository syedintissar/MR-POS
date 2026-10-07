@php
    $user = auth()->user();
    $isStock = $product->isStockItem();
    $stock = (float) $product->current_stock;
    $low = (float) $product->lowStockLevel($threshold);
    $margin = null;
    if ($user->can('products.view_cost') && $isStock && (float) $product->sale_price > 0) {
        $margin = ((float) $product->sale_price - (float) $product->average_cost) / (float) $product->sale_price * 100;
    }
@endphp
<x-layouts.app :title="$product->name">
    <div class="mx-auto max-w-5xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $product->sku }}@if($product->barcode) · {{ $product->barcode }}@endif</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $product->name }}</h2>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    @if($product->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-gray">Inactive</span>@endif
                    @unless($isStock)<span class="badge badge-blue">Service</span>@endunless
                    @if($isStock && $stock <= 0)<span class="badge badge-red">Out of stock</span>@elseif($isStock && $stock <= $low)<span class="badge badge-amber">Low stock</span>@endif
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('products.edit')
                    <a href="{{ route('products.edit', $product) }}" class="btn btn-secondary">Edit</a>
                    <form method="POST" action="{{ route('products.toggle', $product) }}">@csrf
                        <button class="btn btn-secondary" data-no-loading>{{ $product->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                @endcan
                @can('inventory.adjust')@if($isStock)<a href="{{ route('adjustments.create', ['product' => $product->id]) }}" class="btn btn-secondary">Adjust stock</a>@endif @endcan
                @can('products.delete')
                    <form method="POST" action="{{ route('products.destroy', $product) }}" data-confirm="Delete “{{ $product->name }}”? This cannot be undone." data-confirm-label="Delete" data-danger="1">
                        @csrf @method('DELETE')<button class="btn btn-secondary text-red-600" @disabled($hasHistory) title="{{ $hasHistory ? 'Has stock history: deactivate instead' : '' }}">Delete</button></form>
                @endcan
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">In stock</div>
                        @if($isStock)
                            <div class="mt-1 text-2xl font-semibold {{ $stock <= 0 ? 'text-red-600' : ($stock <= $low ? 'text-amber-600' : 'text-slate-900') }}">{{ \App\Support\Decimal::trim($product->current_stock) }} <span class="text-sm font-normal text-slate-500">{{ $product->unit->short_name }}</span></div>
                            <div class="text-xs text-slate-500">warn at {{ \App\Support\Decimal::trim($product->lowStockLevel($threshold)) }} or less</div>
                        @else<div class="mt-1 text-lg text-slate-400">Not counted</div>@endif
                    </div>
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sale price</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-900">{{ number_format((float) $product->sale_price, 2) }}</div>
                        <div class="text-xs text-slate-500">
                            @if($product->wholesale_price !== null)wholesale {{ number_format((float) $product->wholesale_price, 2) }}@endif
                            @if($product->min_sale_price !== null) · lowest {{ number_format((float) $product->min_sale_price, 2) }}@endif
                        </div>
                    </div>
                    @can('products.view_cost')
                        <div class="card card-body">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Average cost</div>
                            @if($isStock)
                                <div class="mt-1 text-2xl font-semibold text-slate-900">{{ number_format((float) $product->average_cost, 2) }}</div>
                                <div class="text-xs text-slate-500">last bought at {{ number_format((float) $product->last_purchase_cost, 2) }}@if($margin !== null) · margin <span class="{{ $margin < 0 ? 'text-red-600' : 'text-emerald-700' }} font-medium">{{ number_format($margin, 1) }}%</span>@endif</div>
                            @else<div class="mt-1 text-lg text-slate-400">—</div>@endif
                        </div>
                    @endcan
                </div>

                @if($user->can('inventory.view') && $isStock)
                    <div class="card overflow-x-auto">
                        <div class="card-header"><h3 class="card-title">Recent stock movements</h3>
                            <a href="{{ route('inventory.movements', ['product' => $product->id]) }}" class="text-sm">Full stock card →</a></div>
                        <table class="table">
                            <thead><tr><th>When</th><th>What happened</th><th class="text-right">Change</th><th class="text-right">Stock after</th><th>By</th></tr></thead>
                            <tbody>
                            @forelse($movements as $m)
                                <tr>
                                    <td class="whitespace-nowrap text-slate-600">{{ $m->created_at->format('d M Y, H:i') }}</td>
                                    <td>{{ \App\Models\StockMovement::label($m->movement_type) }}@if($m->notes)<div class="text-xs text-slate-500">{{ $m->notes }}</div>@endif</td>
                                    <td class="text-right font-medium {{ (float) $m->quantity < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ (float) $m->quantity > 0 ? '+' : '' }}{{ \App\Support\Decimal::trim($m->quantity) }}</td>
                                    <td class="text-right">{{ \App\Support\Decimal::trim($m->qty_after) }}</td>
                                    <td class="text-xs text-slate-500">{{ $m->user?->name ?? 'system' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No stock movements yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="space-y-5">
                @if($product->image_path)
                    <div class="card card-body"><img src="{{ route('products.image', $product) }}?v={{ $product->updated_at?->timestamp }}" alt="{{ $product->name }}" class="max-h-64 w-full rounded object-contain"></div>
                @endif
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Details</h3></div>
                    <dl class="divide-y divide-slate-100 text-sm">
                        @foreach([
                            'Category' => ($product->category?->parent ? $product->category->parent->name.' › ' : '').$product->category?->name,
                            'Brand' => $product->brand?->name, 'Model' => $product->model, 'Unit' => $product->unit?->name,
                            'Shelf' => $product->rack_location, 'Usual supplier' => $product->defaultSupplier?->name,
                            'Tax rate' => $product->tax_rate !== null ? $product->tax_rate.'%' : 'shop default',
                            'Warranty' => $product->warranty_months ? $product->warranty_months.' months'.($product->warranty_terms ? ' · '.$product->warranty_terms : '') : null,
                        ] as $label => $value)
                            @if($value !== null && $value !== '')
                                <div class="flex justify-between gap-3 px-5 py-2"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-right text-slate-800">{{ $value }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                    @if($product->description)<p class="border-t border-slate-100 px-5 py-3 text-sm text-slate-600">{{ $product->description }}</p>@endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
