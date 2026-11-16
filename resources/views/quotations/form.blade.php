@php
    $editing = $quotation->exists;
    $currency = \App\Support\Money::symbol();
@endphp
<x-layouts.app :title="$editing ? 'Edit quotation '.$quotation->quotation_no : 'New quotation'">
    <script>
        window.quotationForm = function (initial, customers, searchUrl, customerId, discount) {
            const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
            const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
            return {
                lines: initial, customers, customerId, discount, nextKey: initial.length + 1,
                query: '', results: [], timer: null,
                get customer() { return this.customers.find(c => String(c.id) === String(this.customerId)) ?? null; },
                search() {
                    clearTimeout(this.timer);
                    if (!searchUrl || this.query.trim().length < 1) { this.results = []; return; }
                    this.timer = setTimeout(async () => {
                        const r = await fetch(searchUrl + '?q=' + encodeURIComponent(this.query.trim()), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        this.results = r.ok ? await r.json() : [];
                    }, 200);
                },
                add(p) {
                    const wholesale = this.customer && this.customer.price_tier === 'wholesale' && p.wholesale_price;
                    this.lines.push({ key: this.nextKey++, product_id: p.id, sku: p.sku, unit: p.unit, description: p.name, quantity: '1', unit_price: wholesale ? p.wholesale_price : p.price });
                    this.query = ''; this.results = [];
                },
                addTyped() { this.lines.push({ key: this.nextKey++, product_id: null, sku: null, unit: null, description: '', quantity: '1', unit_price: '' }); },
                remove(i) { this.lines.splice(i, 1); },
                lineTotal(l) { return r2(num(l.quantity) * num(l.unit_price)); },
                get subtotal() { return r2(this.lines.reduce((s, l) => s + this.lineTotal(l), 0)); },
                get grand() { return r2(this.subtotal - num(this.discount)); },
                money(v) { return (v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            };
        };
    </script>

    <form method="POST" action="{{ $editing ? route('quotations.update', $quotation) : route('quotations.store') }}" class="mx-auto max-w-5xl space-y-5"
          x-data="quotationForm(@js($lines), @js($customers->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'price_tier' => $c->price_tier])->values()), @js($canSearch ? route('pos.products.search') : null), @js((string) old('customer_id', $quotation->customer_id ?? '')), @js((string) old('discount_amount', (float) $quotation->discount_amount ? \App\Support\Decimal::trim((string) $quotation->discount_amount) : '')))">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <div class="card-header"><h2 class="card-title">Who is it for?</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="customer_id">Customer</label>
                    <select id="customer_id" name="customer_id" x-model="customerId" class="input">
                        <option value="">— not a customer yet: type the name —</option>
                        @foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}{{ $c->phone ? ' · '.$c->phone : '' }}</option>@endforeach
                    </select>
                    @error('customer_id')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <div x-show="!customerId"><x-form.input name="customer_name" label="Name (person or office)" :value="$quotation->customer_id ? '' : $quotation->customer_name" /></div>
                <div x-show="!customerId"><x-form.input name="customer_phone" label="Phone" :value="$quotation->customer_id ? '' : $quotation->customer_phone" /></div>
                <x-form.input name="quote_date" label="Date" type="date" :value="optional($quotation->quote_date)->toDateString()" required />
                <x-form.input name="valid_until" label="Valid until" type="date" :value="optional($quotation->valid_until)->toDateString()" help="Leave empty for no end date." />
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">What is offered</h2></div>
            <div class="card-body space-y-4">
                <div class="flex flex-wrap items-end gap-3">
                    @if($canSearch)
                        <div class="relative min-w-64 flex-1">
                            <label class="label" for="find">Add a product: type a name, SKU or model</label>
                            <input id="find" type="search" x-model="query" @input="search()" @keydown.enter.prevent autocomplete="off" class="input" placeholder="Start typing…">
                            <div x-show="results.length" x-cloak class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg" @click.outside="results = []">
                                <template x-for="p in results" :key="p.id">
                                    <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50" data-no-loading @click="add(p)">
                                        <span><span class="font-medium text-slate-900" x-text="p.name"></span> <span class="font-mono text-xs text-slate-500" x-text="p.sku"></span></span>
                                        <span class="text-xs text-slate-500"><span x-text="p.price"></span><span x-show="p.stock !== null" x-text="' · stock ' + p.stock"></span></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    @endif
                    <button type="button" class="btn btn-secondary" data-no-loading @click="addTyped()">+ Type an item that is not in the product list</button>
                </div>

                @error('lines')<p class="error-text">{{ $message }}</p>@enderror
                @foreach($errors->getMessages() as $key => $messages)
                    @if(str_starts_with($key, 'lines.'))<p class="error-text">{{ $messages[0] }}</p>@endif
                @endforeach

                <div class="overflow-x-auto">
                    <table class="table" x-show="lines.length" x-cloak>
                        <thead><tr><th>Item</th><th>Quantity</th><th>Price each ({{ $currency }})</th><th class="text-right">Total</th><th></th></tr></thead>
                        <tbody>
                        <template x-for="(l, i) in lines" :key="l.key">
                            <tr>
                                <td class="min-w-64">
                                    <input type="hidden" :name="'lines[' + i + '][product_id]'" :value="l.product_id ?? ''">
                                    <input type="text" :name="'lines[' + i + '][description]'" x-model="l.description" maxlength="255" required class="input" placeholder="What is offered">
                                    <div class="mt-0.5 text-[11px] text-slate-500" x-text="l.product_id ? ('from the product list · ' + l.sku) : 'typed: cannot go to the till, add it to the products first to sell it'"></div>
                                </td>
                                <td><input type="number" :name="'lines[' + i + '][quantity]'" x-model="l.quantity" step="0.001" min="0.001" required class="input w-24"></td>
                                <td><input type="number" :name="'lines[' + i + '][unit_price]'" x-model="l.unit_price" step="0.01" min="0" required class="input w-32"></td>
                                <td class="whitespace-nowrap text-right font-medium" x-text="money(lineTotal(l))"></td>
                                <td class="text-right"><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="remove(i)">Remove</button></td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                    <p x-show="!lines.length" class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Nothing yet. Search a product above, or type an item.</p>
                </div>

                <div class="ml-auto w-full max-w-xs space-y-2 text-sm">
                    <div class="flex justify-between"><span>Items</span><span x-text="money(subtotal)"></span></div>
                    <div class="flex items-center justify-between gap-3"><label for="discount_amount">Discount ({{ $currency }})</label>
                        <input id="discount_amount" name="discount_amount" type="number" step="0.01" min="0" x-model="discount" class="input w-32 text-right" placeholder="0"></div>
                    @error('discount_amount')<p class="error-text">{{ $message }}</p>@enderror
                    <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Total ({{ $currency }})</span><span x-text="money(grand)"></span></div>
                </div>
            </div>
        </div>

        <div class="card card-body">
            <x-form.textarea name="notes" label="Terms and notes (printed on the quotation)" :value="$quotation->notes" rows="3" help="For example: delivery time, warranty, payment terms." />
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <a href="{{ $editing ? route('quotations.show', $quotation) : route('quotations.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="!lines.length" data-loading-text="Saving…">Save quotation</button>
        </div>
    </form>
</x-layouts.app>
