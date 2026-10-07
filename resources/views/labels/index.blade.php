<x-layouts.app title="Barcode labels">
    <div class="mx-auto max-w-5xl space-y-4">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Find products</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Name, SKU, barcode or model" class="input w-64" autofocus></div>
            <div><label class="label">Category</label>
                <select name="category" class="input w-48"><option value="">All</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>
                        @foreach($c->children as $ch)<option value="{{ $ch->id }}" @selected(request('category') == $ch->id)>&nbsp;&nbsp;↳ {{ $ch->name }}</option>@endforeach
                    @endforeach</select></div>
            <button class="btn btn-secondary" data-no-loading>Search</button>
        </form>

        <form method="GET" action="{{ route('labels.print') }}" target="_blank" class="space-y-4" x-data>
            <div class="card card-body grid gap-4 sm:grid-cols-3">
                <div><label class="label" for="size">Label size</label>
                    <select id="size" name="size" class="input">@foreach($sizes as $k => $s)<option value="{{ $k }}">{{ $s['label'] }}</option>@endforeach</select></div>
                <div class="flex items-end"><x-form.checkbox name="show_price" label="Print the price on the label" :checked="false" /></div>
                <div class="flex items-end justify-end gap-2">
                    <button type="button" class="btn btn-secondary" data-no-loading
                            @click="document.querySelectorAll('[data-stock]').forEach(i => i.value = Math.max(0, Math.round(parseFloat(i.dataset.stock) || 0)))">Fill with stock on hand</button>
                    <button type="button" class="btn btn-secondary" data-no-loading @click="document.querySelectorAll('[data-stock]').forEach(i => i.value = 0)">Clear</button>
                    <button type="submit" class="btn btn-primary" data-no-loading>Print labels</button>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table class="table">
                    <thead><tr><th>SKU</th><th>Product</th><th>Barcode printed</th><th class="text-right">In stock</th><th class="w-32">How many labels</th></tr></thead>
                    <tbody>
                    @forelse($products as $p)
                        <tr>
                            <td class="font-mono text-xs text-slate-600">{{ $p->sku }}</td>
                            <td class="font-medium text-slate-900">{{ $p->name }}</td>
                            <td class="font-mono text-xs text-slate-600">{{ $p->barcode ?: $p->sku }} @unless($p->barcode)<span class="text-slate-400">(SKU)</span>@endunless</td>
                            <td class="text-right">{{ \App\Support\Decimal::trim($p->current_stock) }} {{ $p->unit->short_name }}</td>
                            <td><input type="number" min="0" max="500" name="items[{{ $p->id }}]" value="0" data-stock="{{ $p->current_stock }}" class="input"></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No products found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="help">Labels use the product's barcode, or its SKU when it has none (Code 128, readable by any scanner). Only the products listed above are included; search again to add others. The label page opens in a new tab and prints from there.</p>
        </form>
    </div>
</x-layouts.app>
