<x-layouts.app title="Adjust stock">
    <script>
        window.adjustmentForm = function (initial, lookupUrl, canCost, allowNegative) {
            return {
                reason: @js(old('reason', 'count_correction')),
                lines: initial,
                query: '', results: [], searching: false, timer: null, pressedEnter: false,
                canCost, allowNegative,
                search() {
                    clearTimeout(this.timer);
                    if (this.query.trim().length < 1) { this.results = []; return; }
                    this.timer = setTimeout(async () => {
                        this.searching = true;
                        try {
                            const r = await fetch(lookupUrl + '?q=' + encodeURIComponent(this.query.trim()), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                            this.results = r.ok ? await r.json() : [];
                            // A scanner types the code and presses Enter: add the exact match straight away.
                            const exact = this.results.find(p => p.barcode === this.query.trim() || p.sku.toLowerCase() === this.query.trim().toLowerCase());
                            if (exact && this.pressedEnter) { this.add(exact); }
                        } finally { this.searching = false; this.pressedEnter = false; }
                    }, 200);
                },
                add(p) {
                    if (!this.lines.some(l => l.id === p.id)) {
                        this.lines.push({ id: p.id, sku: p.sku, name: p.name, unit: p.unit, allows_decimal: p.allows_decimal, stock: p.stock, direction: this.reason === 'opening' ? 'in' : 'in', quantity: '', unit_cost: '', notes: '' });
                    }
                    this.query = ''; this.results = [];
                    this.$nextTick(() => { const el = document.querySelector('[data-qty="' + p.id + '"]'); if (el) el.focus(); });
                },
                remove(i) { this.lines.splice(i, 1); },
                after(l) {
                    const q = parseFloat(l.quantity); const s = parseFloat(l.stock);
                    if (isNaN(q)) return null;
                    return +(s + (l.direction === 'out' ? -q : q)).toFixed(3);
                },
                tooLow(l) { const a = this.after(l); return a !== null && a < 0 && !this.allowNegative; },
                reasonChanged() { if (this.reason === 'opening') this.lines.forEach(l => l.direction = 'in'); },
                get hasProblem() { return this.lines.some(l => this.tooLow(l)); },
            };
        };
    </script>

    <form method="POST" action="{{ route('adjustments.store') }}" class="mx-auto max-w-5xl space-y-5"
          x-data="adjustmentForm(@js($lines), @js(route('products.lookup')), @js($canCost), @js($allowNegative))">
        @csrf

        <div class="card">
            <div class="card-header"><h2 class="card-title">Why are you changing the stock?</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="reason">Reason <span class="text-red-500">*</span></label>
                    <select id="reason" name="reason" x-model="reason" @change="reasonChanged()" class="input">
                        @foreach($reasons as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                    </select>
                    @error('reason')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="adjustment_date" label="Date" type="date" :value="now()->toDateString()" required :max="now()->toDateString()" />
                <x-form.input name="notes" label="Note (optional)" placeholder="e.g. monthly count, shelf A" />
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Products</h2></div>
            <div class="card-body space-y-4">
                <div class="relative">
                    <label class="label" for="find">Add a product: type a name, SKU or scan a barcode</label>
                    <input id="find" type="search" x-model="query" @input="search()" @keydown.enter.prevent="pressedEnter = true; search()" autocomplete="off" class="input" placeholder="Start typing…" autofocus>
                    <div x-show="results.length" x-cloak class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg" @click.outside="results = []">
                        <template x-for="p in results" :key="p.id">
                            <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50" data-no-loading @click="add(p)">
                                <span><span class="font-medium text-slate-900" x-text="p.name"></span> <span class="font-mono text-xs text-slate-500" x-text="p.sku"></span></span>
                                <span class="text-xs text-slate-500">in stock: <span x-text="p.stock"></span> <span x-text="p.unit"></span></span>
                            </button>
                        </template>
                    </div>
                    <p x-show="searching" x-cloak class="help">Searching…</p>
                </div>

                @error('lines')<p class="error-text">{{ $message }}</p>@enderror

                <div class="overflow-x-auto">
                    <table class="table" x-show="lines.length" x-cloak>
                        <thead><tr><th>Product</th><th class="text-right">In stock</th><th>Change</th><th>Quantity</th>@if($canCost)<th>Cost each</th>@endif<th class="text-right">Will become</th><th></th></tr></thead>
                        <tbody>
                        <template x-for="(l, i) in lines" :key="l.id">
                            <tr :class="tooLow(l) ? 'bg-red-50' : ''">
                                <td>
                                    <input type="hidden" :name="'lines[' + i + '][product_id]'" :value="l.id">
                                    <div class="font-medium text-slate-900" x-text="l.name"></div><div class="font-mono text-xs text-slate-500" x-text="l.sku"></div>
                                    <input type="text" :name="'lines[' + i + '][notes]'" x-model="l.notes" placeholder="note (optional)" class="input mt-1 text-xs">
                                </td>
                                <td class="text-right whitespace-nowrap"><span x-text="l.stock"></span> <span class="text-xs text-slate-400" x-text="l.unit"></span></td>
                                <td>
                                    <select :name="'lines[' + i + '][direction]'" x-model="l.direction" class="input w-28">
                                        <option value="in">Add</option>
                                        <option value="out" :disabled="reason === 'opening'">Remove</option>
                                    </select>
                                </td>
                                <td><input type="number" :name="'lines[' + i + '][quantity]'" x-model="l.quantity" :data-qty="l.id" :step="l.allows_decimal ? '0.001' : '1'" min="0" required class="input w-28"></td>
                                @if($canCost)<td><input type="number" :name="'lines[' + i + '][unit_cost]'" x-model="l.unit_cost" x-show="l.direction === 'in'" step="0.01" min="0" placeholder="average" class="input w-28"></td>@endif
                                <td class="whitespace-nowrap text-right">
                                    <span x-text="after(l) === null ? '—' : after(l)" :class="tooLow(l) ? 'font-semibold text-red-600' : 'font-medium'"></span>
                                    <div x-show="tooLow(l)" class="text-xs text-red-600">not enough stock</div>
                                </td>
                                <td class="text-right"><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="remove(i)">Remove</button></td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                    <p x-show="!lines.length" class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No products yet. Search above to add the ones you want to change.</p>
                </div>
                @if($canCost)<p class="help">“Cost each” is only used when you add stock. Leave it empty to use the current average cost.</p>@endif
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="!lines.length || hasProblem" data-loading-text="Posting…">Post adjustment</button>
        </div>
    </form>
</x-layouts.app>
