@php($title = $product ? 'Stock card: '.$product->name : 'Stock movements')
<x-layouts.app :title="$title">
    <form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @if($product)
            <input type="hidden" name="product" value="{{ $product->id }}">
            <div class="sm:col-span-3 lg:col-span-2">
                <div class="label">Product</div>
                <div class="flex items-center gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm"><a href="{{ route('products.show', $product) }}" class="font-medium">{{ $product->sku }} · {{ $product->name }}</a>
                    <a href="{{ route('inventory.movements', request()->except('product')) }}" class="ml-auto text-xs text-slate-500">show all products</a></div>
            </div>
        @endif
        <div><label class="label">From</label><input type="date" name="from" value="{{ request('from') }}" class="input"></div>
        <div><label class="label">To</label><input type="date" name="to" value="{{ request('to') }}" class="input"></div>
        <div><label class="label">Type</label>
            <select name="type" class="input"><option value="">All</option>@foreach($types as $t)<option value="{{ $t }}" @selected(request('type') === $t)>{{ \App\Models\StockMovement::label($t) }}</option>@endforeach</select></div>
        <div><label class="label">By</label>
            <select name="user" class="input"><option value="">Anyone</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
        <div class="flex items-end gap-2"><button class="btn btn-primary" data-no-loading>Filter</button><a href="{{ route('inventory.movements', $product ? ['product' => $product->id] : []) }}" class="btn btn-secondary">Clear</a></div>
    </form>

    <p class="mb-3 text-sm text-slate-500">Every change to stock is recorded here and can never be edited or deleted. Each row starts where the previous one ended.</p>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>When</th>@unless($product)<th>Product</th>@endunless<th>What happened</th><th class="text-right">Before</th><th class="text-right">Change</th><th class="text-right">After</th>@if($showCost)<th class="text-right">Unit cost</th>@endif<th>Document</th><th>By</th></tr></thead>
            <tbody>
            @forelse($movements as $m)
                <tr>
                    <td class="whitespace-nowrap text-slate-600">{{ $m->created_at->format('d M Y, H:i') }}</td>
                    @unless($product)<td><a href="{{ route('inventory.movements', ['product' => $m->product_id]) }}" class="text-slate-900 hover:text-brand-700">{{ $m->product->name }}</a><div class="font-mono text-xs text-slate-500">{{ $m->product->sku }}</div></td>@endunless
                    <td>{{ \App\Models\StockMovement::label($m->movement_type) }}@if($m->notes)<div class="text-xs text-slate-500">{{ $m->notes }}</div>@endif</td>
                    <td class="text-right text-slate-500">{{ \App\Support\Decimal::trim($m->qty_before) }}</td>
                    <td class="text-right font-medium {{ (float) $m->quantity < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ (float) $m->quantity > 0 ? '+' : '' }}{{ \App\Support\Decimal::trim($m->quantity) }}</td>
                    <td class="text-right font-medium">{{ \App\Support\Decimal::trim($m->qty_after) }}</td>
                    @if($showCost)<td class="text-right text-slate-500">{{ number_format((float) $m->unit_cost, 2) }}</td>@endif
                    <td class="whitespace-nowrap">
                        @if($m->source_type === 'stock_adjustment' && isset($adjustmentNos[$m->source_id]))<a href="{{ route('adjustments.show', $m->source_id) }}">{{ $adjustmentNos[$m->source_id] }}</a>@else <span class="text-slate-400">—</span>@endif
                    </td>
                    <td class="text-xs text-slate-500">{{ $m->user?->name ?? 'system' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-12 text-center text-slate-500">No movements match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $movements->links() }}</div>
</x-layouts.app>
