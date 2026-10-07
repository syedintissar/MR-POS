@php
    $editing = $purchase->exists;
    $currency = \App\Support\Money::symbol();
@endphp
<x-layouts.app :title="$editing ? 'Edit purchase draft #'.$purchase->id : 'New purchase'">
    <script>
        window.purchaseForm = function (initial, supplierId, lookupUrl, taxInCost, totals) {
            const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
            const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
            return {
                lines: initial, supplierId, taxInCost,
                discount: totals.discount, tax: totals.tax, additional: totals.additional, payAmount: totals.payAmount,
                query: '', results: [], searching: false, timer: null, pressedEnter: false,
                search() {
                    clearTimeout(this.timer);
                    if (this.query.trim().length < 1) { this.results = []; return; }
                    this.timer = setTimeout(async () => {
                        this.searching = true;
                        try {
                            const url = lookupUrl + '?q=' + encodeURIComponent(this.query.trim()) + (this.supplierId ? '&supplier_id=' + this.supplierId : '');
                            const r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                            this.results = r.ok ? await r.json() : [];
                            // A scanner types the code and presses Enter: add the exact match straight away.
                            const exact = this.results.find(p => p.barcode === this.query.trim() || p.sku.toLowerCase() === this.query.trim().toLowerCase());
                            if (exact && this.pressedEnter) { this.add(exact); }
                        } finally { this.searching = false; this.pressedEnter = false; }
                    }, 200);
                },
                add(p) {
                    if (!this.lines.some(l => l.id === p.id)) {
                        this.lines.push({
                            id: p.id, sku: p.sku, name: p.name, unit: p.unit, allows_decimal: p.allows_decimal, stock: p.stock,
                            quantity: '', unit_cost: p.supplier_last_cost ?? p.last_cost ?? '', discount_amount: '', tax_amount: '',
                            last_cost: p.last_cost ?? null,                                // last LANDED cost: what the trend arrow compares with
                            last_price: p.supplier_last_cost ?? p.last_cost ?? null,       // last price on a supplier invoice: shown under the cost box
                        });
                    }
                    this.query = ''; this.results = [];
                    this.$nextTick(() => { const el = document.querySelector('[data-qty="' + p.id + '"]'); if (el) el.focus(); });
                },
                remove(i) { this.lines.splice(i, 1); },
                lineTotal(l) { return r2(r2(num(l.quantity) * num(l.unit_cost)) - num(l.discount_amount) + num(l.tax_amount)); },
                get subtotal() { return r2(this.lines.reduce((s, l) => s + this.lineTotal(l), 0)); },
                get grand() { return r2(this.subtotal - num(this.discount) + num(this.tax) + num(this.additional)); },
                // estimate only (the server does the exact calculation): what one unit really costs once freight, discount and tax are shared in
                landed(l) {
                    const base = (x) => num(x.quantity) * num(x.unit_cost) - num(x.discount_amount) + (this.taxInCost ? num(x.tax_amount) : 0);
                    const sum = this.lines.reduce((s, x) => s + base(x), 0);
                    const pool = num(this.additional) + (this.taxInCost ? num(this.tax) : 0) - num(this.discount);
                    const q = num(l.quantity);
                    if (q <= 0) return null;
                    const share = sum > 0 ? pool * base(l) / sum : 0;
                    return Math.max(0, (base(l) + share) / q);
                },
                trend(l) {
                    const c = this.landed(l); const last = num(l.last_cost);
                    if (c === null || !last) return '';
                    const d = (c - last) / last * 100;
                    if (Math.abs(d) < 0.5) return '';
                    return (d > 0 ? '▲ ' : '▼ ') + Math.abs(d).toFixed(0) + '% vs last landed cost';
                },
                money(v) { return (v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                get overPay() { return num(this.payAmount) > this.grand + 0.001; },
                get hasLines() { return this.lines.length > 0; },
            };
        };
    </script>

    <form method="POST" action="{{ $editing ? route('purchases.update', $purchase) : route('purchases.store') }}" class="mx-auto max-w-6xl space-y-5"
          x-data="purchaseForm(@js($lines), @js((string) old('supplier_id', $purchase->supplier_id)), @js(route('purchases.lookup')), @js($taxInCost), @js([
              'discount' => old('discount_amount', (float) $purchase->discount_amount ?: ''),
              'tax' => old('tax_amount', (float) $purchase->tax_amount ?: ''),
              'additional' => old('additional_cost', (float) $purchase->additional_cost ?: ''),
              'payAmount' => old('pay_amount', ''),
          ]))">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <div class="card-header"><h2 class="card-title">Who is it from?</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="supplier_id">Supplier <span class="text-red-500">*</span></label>
                    <select id="supplier_id" name="supplier_id" x-model="supplierId" required class="input {{ $errors->has('supplier_id') ? 'input-error' : '' }}">
                        <option value="">Choose…</option>
                        @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}{{ $s->company ? ' · '.$s->company : '' }}</option>@endforeach
                    </select>
                    @error('supplier_id')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="supplier_invoice_no" label="Supplier's invoice number" :value="$purchase->supplier_invoice_no" help="Optional. The same number cannot be entered twice for one supplier." />
                <x-form.input name="purchase_date" label="Date" type="date" :value="optional($purchase->purchase_date)->toDateString() ?? now()->toDateString()" required :max="now()->toDateString()" />
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Products bought</h2></div>
            <div class="card-body space-y-4">
                <div class="relative">
                    <label class="label" for="find">Add a product: type a name, SKU or scan a barcode</label>
                    <input id="find" type="search" x-model="query" @input="search()" @keydown.enter.prevent="pressedEnter = true; search()" autocomplete="off" class="input" placeholder="Start typing…">
                    <div x-show="results.length" x-cloak class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg" @click.outside="results = []">
                        <template x-for="p in results" :key="p.id">
                            <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50" data-no-loading @click="add(p)">
                                <span><span class="font-medium text-slate-900" x-text="p.name"></span> <span class="font-mono text-xs text-slate-500" x-text="p.sku"></span></span>
                                <span class="text-right text-xs text-slate-500">
                                    in stock: <span x-text="p.stock"></span> <span x-text="p.unit"></span>
                                    <template x-if="p.supplier_last_cost"><span class="block">last from this supplier: <span x-text="p.supplier_last_cost"></span></span></template>
                                    <template x-if="!p.supplier_last_cost && p.last_cost"><span class="block">last cost: <span x-text="p.last_cost"></span></span></template>
                                </span>
                            </button>
                        </template>
                    </div>
                    <p x-show="searching" x-cloak class="help">Searching…</p>
                </div>

                @error('lines')<p class="error-text">{{ $message }}</p>@enderror
                @foreach($errors->getMessages() as $key => $messages)
                    @if(str_starts_with($key, 'lines.'))<p class="error-text">{{ $messages[0] }}</p>@endif
                @endforeach

                <div class="overflow-x-auto">
                    <table class="table" x-show="hasLines" x-cloak>
                        <thead><tr><th>Product</th><th>Quantity</th><th>Cost each</th><th>Discount</th><th>Tax</th><th class="text-right">Line total</th><th class="text-right">Landed each</th><th></th></tr></thead>
                        <tbody>
                        <template x-for="(l, i) in lines" :key="l.id">
                            <tr>
                                <td>
                                    <input type="hidden" :name="'lines[' + i + '][product_id]'" :value="l.id">
                                    <div class="font-medium text-slate-900" x-text="l.name"></div>
                                    <div class="font-mono text-xs text-slate-500"><span x-text="l.sku"></span> · in stock <span x-text="l.stock"></span> <span x-text="l.unit"></span></div>
                                </td>
                                <td><input type="number" :name="'lines[' + i + '][quantity]'" x-model="l.quantity" :data-qty="l.id" :step="l.allows_decimal ? '0.001' : '1'" min="0" required class="input w-24"></td>
                                <td><input type="number" :name="'lines[' + i + '][unit_cost]'" x-model="l.unit_cost" step="any" min="0" required class="input w-28">
                                    <div class="mt-0.5 text-[11px] text-slate-500" x-show="l.last_price">last: <span x-text="l.last_price"></span></div></td>
                                <td><input type="number" :name="'lines[' + i + '][discount_amount]'" x-model="l.discount_amount" step="0.01" min="0" class="input w-24" placeholder="0"></td>
                                <td><input type="number" :name="'lines[' + i + '][tax_amount]'" x-model="l.tax_amount" step="0.01" min="0" class="input w-24" placeholder="0"></td>
                                <td class="whitespace-nowrap text-right font-medium" x-text="money(lineTotal(l))"></td>
                                <td class="whitespace-nowrap text-right text-slate-600">
                                    <span x-text="landed(l) === null ? '—' : money(landed(l))"></span>
                                    <div class="text-[11px]" :class="trend(l).startsWith('▲') ? 'text-red-600' : 'text-emerald-700'" x-text="trend(l)"></div>
                                </td>
                                <td class="text-right"><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="remove(i)">Remove</button></td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                    <p x-show="!hasLines" class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No products yet. Search above to add what you bought.</p>
                </div>
                <p class="help">“Landed each” is what one unit really cost you after its share of the extra costs, the invoice discount
                    @if($taxInCost) and the tax @endif is added in. It becomes the cost used for the average cost. The figure here is an estimate; the exact one is worked out when you save.</p>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Extras on the invoice</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-3">
                    <div><label class="label" for="discount_amount">Invoice discount</label>
                        <input id="discount_amount" name="discount_amount" type="number" step="0.01" min="0" x-model="discount" class="input"> @error('discount_amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                    <div><label class="label" for="tax_amount">Invoice tax</label>
                        <input id="tax_amount" name="tax_amount" type="number" step="0.01" min="0" x-model="tax" class="input"> @error('tax_amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                    <div><label class="label" for="additional_cost">Freight / extra costs</label>
                        <input id="additional_cost" name="additional_cost" type="number" step="0.01" min="0" x-model="additional" class="input"> @error('additional_cost')<p class="error-text">{{ $message }}</p>@enderror</div>
                    <x-form.textarea name="notes" label="Notes" :value="$purchase->notes" rows="2" class="sm:col-span-3" />
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Total</h2></div>
                <div class="card-body space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-slate-600">Products</span><span x-text="money(subtotal)"></span></div>
                    <div class="flex justify-between" x-show="discount"><span class="text-slate-600">Invoice discount</span><span x-text="'− ' + money(parseFloat(discount) || 0)"></span></div>
                    <div class="flex justify-between" x-show="tax"><span class="text-slate-600">Invoice tax</span><span x-text="money(parseFloat(tax) || 0)"></span></div>
                    <div class="flex justify-between" x-show="additional"><span class="text-slate-600">Freight / extra costs</span><span x-text="money(parseFloat(additional) || 0)"></span></div>
                    <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Total to pay the supplier ({{ $currency }})</span><span x-text="money(grand)"></span></div>
                </div>
            </div>
        </div>

        @if($canReceive)
            <div class="card">
                <div class="card-header"><h2 class="card-title">Paid now? (optional)</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-3">
                    <div><label class="label" for="pay_amount">Amount paid at receiving</label>
                        <input id="pay_amount" name="pay_amount" type="number" step="0.01" min="0" x-model="payAmount" class="input" placeholder="0 = on credit">
                        <p class="error-text" x-show="overPay" x-cloak>More than the total of this purchase.</p>
                        @error('pay_amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                    <div><label class="label" for="pay_method_id">Paid by</label>
                        <select id="pay_method_id" name="pay_method_id" class="input">
                            @foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) old('pay_method_id') === $m->id)>{{ $m->name }}</option>@endforeach
                        </select>
                        @error('pay_method_id')<p class="error-text">{{ $message }}</p>@enderror</div>
                    <x-form.input name="pay_reference" label="Reference (cheque / transaction no.)" />
                    <p class="help sm:col-span-3">Used only when you press <strong>Save &amp; receive</strong>. Whatever is not paid now stays on the supplier's account, to be paid later from Supplier payments.</p>
                </div>
            </div>
        @endif

        <div class="flex flex-wrap justify-end gap-2">
            <a href="{{ $editing ? route('purchases.show', $purchase) : route('purchases.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" name="action" value="draft" class="btn btn-secondary" :disabled="!hasLines" data-loading-text="Saving…">Save draft</button>
            @if($canReceive)
                <button type="submit" name="action" value="receive" class="btn btn-primary" :disabled="!hasLines || overPay" data-loading-text="Receiving…">Save &amp; receive stock</button>
            @endif
        </div>
    </form>
</x-layouts.app>
