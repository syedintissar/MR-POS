@php
    use App\Support\Decimal;
    use App\Support\Money;

    $lineData = $purchase ? $purchase->items->map(function ($i) use ($returned) {
        $net = Decimal::sub(Decimal::mul((string) $i->quantity, (string) $i->unit_cost, 2), (string) $i->discount_amount, 2);

        return [
            'id' => $i->id, 'bought' => Decimal::trim((string) $i->quantity), 'left' => Decimal::trim(Decimal::sub((string) $i->quantity, (string) $i->returned_quantity, 3)),
            'value' => Decimal::add($net, (string) $i->tax_amount, 2), 'given' => (string) ($returned[$i->id] ?? '0.00'), 'stock' => Decimal::trim((string) $i->product->current_stock),
        ];
    })->values() : collect();
    $oldLines = old('lines', []);
@endphp
<x-layouts.app title="Send goods back">
    <div class="mx-auto max-w-5xl space-y-5">
        @if(! $purchase && ! $supplier)
            <div class="grid gap-5 lg:grid-cols-2">
                <form method="GET" class="card card-body space-y-3">
                    <h2 class="card-title">Which purchase are the goods from?</h2>
                    <p class="text-sm text-slate-600">Type the purchase number (for example PUR-000123). The supplier credits what they billed for the goods you send back.</p>
                    <x-form.input name="number" label="Purchase number" placeholder="PUR-000123" autofocus required />
                    <div><button class="btn btn-primary">Find purchase</button></div>
                </form>
                <form method="GET" class="card card-body space-y-3">
                    <h2 class="card-title">Or send goods back without a purchase</h2>
                    <p class="text-sm text-slate-600">For goods whose bill was not entered here. You say how much credit the supplier gives for each unit.</p>
                    <div><label class="label" for="supplier">Supplier</label>
                        <select id="supplier" name="supplier" class="input" required><option value="">Choose…</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                    <div><button class="btn btn-secondary">Continue</button></div>
                </form>
            </div>
        @else
            <script>
                window.purchaseReturnForm = function (lines, due, canRefund, old, freeLines, lookupUrl) {
                    const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
                    const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
                    const qty = {};
                    lines.forEach((l) => { qty[l.id] = (old[l.id] || {}).quantity || ''; });
                    return {
                        lines, qty, due, canRefund, settlement: canRefund ? 'refund' : 'adjust_balance', free: freeLines, query: '', found: [], searching: false,
                        lineValue(l) {
                            const q = num(this.qty[l.id]);
                            if (q <= 0) { return 0; }
                            return q >= num(l.left) ? r2(num(l.value) - num(l.given)) : r2(num(l.value) * q / num(l.bought));
                        },
                        get total() { return r2(this.lines.reduce((s, l) => s + this.lineValue(l), 0) + this.free.reduce((s, f) => s + r2(num(f.quantity) * num(f.unit_credit)), 0)); },
                        get applied() { return Math.min(this.total, num(this.due)); },
                        get rest() { return r2(this.total - this.applied); },
                        get tooMany() { return this.lines.some((l) => num(this.qty[l.id]) > num(l.left) + 0.0005); },
                        get invalid() { return this.total <= 0 || this.tooMany; },
                        async search() {
                            if (this.query.trim().length < 1) { this.found = []; return; }
                            this.searching = true;
                            try { const r = await fetch(lookupUrl + '?q=' + encodeURIComponent(this.query.trim()), { headers: { Accept: 'application/json' } }); this.found = r.ok ? await r.json() : []; } catch (e) { this.found = []; }
                            this.searching = false;
                        },
                        add(p) { if (!this.free.some((f) => f.product_id === p.id)) { this.free.push({ product_id: p.id, name: p.name, sku: p.sku, stock: p.stock, quantity: '1', unit_credit: '' }); } this.found = []; this.query = ''; },
                        money(v) { return num(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                    };
                };
            </script>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $purchase ? 'Purchase' : 'Without a purchase' }}</div>
                    <div class="mt-1 font-mono text-lg font-semibold text-slate-900">{{ $purchase?->purchase_no ?? '—' }}</div>
                    <div class="text-xs text-slate-500">@if($purchase){{ $purchase->purchase_date->format('d M Y') }} · @endif<a href="{{ route('purchase-returns.create') }}">choose another</a></div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Supplier</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ ($purchase?->supplier ?? $supplier)->name }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $purchase ? 'Bill total / still to pay' : 'Their account with us' }}</div>
                    @if($purchase)
                        <div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::withSymbol($purchase->grand_total) }}</div>
                        <div class="text-xs {{ (float) $purchase->due_amount > 0 ? 'text-amber-700' : 'text-slate-500' }}">{{ (float) $purchase->due_amount > 0 ? 'still owe '.Money::format($purchase->due_amount) : 'fully paid' }}</div>
                    @else
                        <div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::withSymbol(abs((float) $supplier->balance)) }}</div>
                        <div class="text-xs text-slate-500">{{ (float) $supplier->balance > 0 ? 'we owe them' : ((float) $supplier->balance < 0 ? 'they hold our credit' : 'nothing owed either way') }}</div>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ $purchase ? route('purchase-returns.store', $purchase) : route('purchase-returns.store-free') }}" class="space-y-5"
                  x-data="purchaseReturnForm(@js($lineData), @js($purchase ? (string) $purchase->due_amount : '0'), @js($canRefund), @js($oldLines), @js($purchase ? [] : array_values(is_array($oldLines) ? $oldLines : [])), @js(route('products.lookup')))">
                @csrf
                @unless($purchase)<input type="hidden" name="supplier_id" value="{{ $supplier->id }}">@endunless

                <div class="card">
                    <div class="card-header"><h2 class="card-title">What is going back?</h2></div>
                    @if($purchase)
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead><tr><th>Item</th><th class="text-right">Received</th><th class="text-right">Already sent back</th><th class="text-right">In stock now</th><th class="text-right">Send back</th><th class="text-right">Credit</th></tr></thead>
                                <tbody>
                                @foreach($purchase->items as $item)
                                    <tr x-data="{ l: lines.find(x => x.id === {{ $item->id }}) }">
                                        <td><span class="font-medium text-slate-900">{{ $item->product->name }}</span><div class="font-mono text-xs text-slate-500">{{ $item->product->sku }}</div></td>
                                        <td class="text-right">{{ Decimal::trim((string) $item->quantity) }}</td>
                                        <td class="text-right text-slate-500">{{ (float) $item->returned_quantity ? Decimal::trim((string) $item->returned_quantity) : '' }}</td>
                                        <td class="text-right text-slate-500">{{ Decimal::trim((string) $item->product->current_stock) }}</td>
                                        <td class="text-right">
                                            @if(Decimal::cmp((string) $item->quantity, (string) $item->returned_quantity) > 0)
                                                <input type="number" step="0.001" min="0" max="{{ Decimal::sub((string) $item->quantity, (string) $item->returned_quantity, 3) }}" name="lines[{{ $item->id }}][quantity]" x-model="qty[{{ $item->id }}]" class="input w-24 text-right" aria-label="Quantity of {{ $item->product->name }} to send back">
                                            @else
                                                <span class="text-slate-400">all sent back</span>
                                            @endif
                                        </td>
                                        <td class="text-right font-medium" x-text="lineValue(l) > 0 ? money(lineValue(l)) : ''"></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @error('lines')<p class="error-text px-5 pb-3">{{ $message }}</p>@enderror
                        <p class="px-5 pb-4 text-xs text-slate-500">The supplier credits what it billed for the units (after the line's discount); the last units of a line take whatever is left. The goods leave your stock at what they cost to land.</p>
                    @else
                        <div class="card-body space-y-3">
                            <div class="relative max-w-md">
                                <label class="label" for="pr_search">Find the product</label>
                                <input id="pr_search" type="search" x-model="query" @input.debounce.250ms="search()" placeholder="Name, SKU or barcode" class="input" autocomplete="off">
                                <div x-show="found.length" x-cloak class="absolute z-10 mt-1 w-full rounded-md border border-slate-200 bg-white shadow-lg">
                                    <template x-for="p in found" :key="p.id"><button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50" data-no-loading @click="add(p)"><span x-text="p.name"></span> <span class="font-mono text-xs text-slate-400" x-text="p.sku"></span> <span class="text-xs text-slate-500">· <span x-text="p.stock"></span> in stock</span></button></template>
                                </div>
                            </div>
                            <p class="text-sm text-slate-500" x-show="free.length === 0">Search for the products you are sending back.</p>
                            <table class="table" x-show="free.length" x-cloak>
                                <thead><tr><th>Product</th><th class="text-right">In stock</th><th class="text-right">Quantity</th><th class="text-right">Credit per unit</th><th class="text-right">Credit</th><th></th></tr></thead>
                                <tbody>
                                <template x-for="(f, i) in free" :key="f.product_id">
                                    <tr>
                                        <td><span class="font-medium" x-text="f.name"></span><div class="font-mono text-xs text-slate-500" x-text="f.sku"></div><input type="hidden" :name="'lines[' + i + '][product_id]'" :value="f.product_id"></td>
                                        <td class="text-right text-slate-500" x-text="f.stock"></td>
                                        <td class="text-right"><input type="number" step="0.001" min="0.001" :name="'lines[' + i + '][quantity]'" x-model="f.quantity" class="input w-24 text-right" required></td>
                                        <td class="text-right"><input type="number" step="0.01" min="0" :name="'lines[' + i + '][unit_credit]'" x-model="f.unit_credit" class="input w-28 text-right" required></td>
                                        <td class="text-right font-medium" x-text="money((parseFloat(f.quantity) || 0) * (parseFloat(f.unit_credit) || 0))"></td>
                                        <td class="text-right"><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="free.splice(i, 1)">remove</button></td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                            @error('lines')<p class="error-text">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>

                <div class="card">
                    <div class="card-header"><h2 class="card-title">How does the supplier settle it?</h2></div>
                    <div class="card-body space-y-3">
                        <div class="grid gap-3 text-sm sm:grid-cols-3">
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Credit for the goods</div><div class="text-lg font-semibold" x-text="money(total)"></div></div>
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Reduces what we owe on the bill</div><div class="text-lg font-semibold" x-text="money(applied)"></div></div>
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Left over</div><div class="text-lg font-semibold" :class="rest > 0 ? 'text-amber-700' : ''" x-text="money(rest)"></div></div>
                        </div>
                        <div x-show="rest > 0" x-cloak class="space-y-2">
                            <div class="flex flex-wrap gap-4 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" name="settlement" value="adjust_balance" x-model="settlement" class="h-4 w-4 text-brand-600"> Keep it as credit on the supplier's account</label>
                                <label class="flex items-center gap-2" x-show="canRefund"><input type="radio" name="settlement" value="refund" x-model="settlement" class="h-4 w-4 text-brand-600"> The supplier pays the money back</label>
                            </div>
                            <div x-show="settlement === 'refund'" x-cloak class="grid max-w-xl gap-3 sm:grid-cols-2">
                                <div><label class="label" for="payment_method_id">Received by</label>
                                    <select id="payment_method_id" name="payment_method_id" class="input">@foreach($methods as $m)<option value="{{ $m->id }}">{{ $m->name }}{{ $m->requires_reference ? ' (needs a reference)' : '' }}</option>@endforeach</select></div>
                                <x-form.input name="reference_no" label="Reference" help="Cheque, transaction or slip number" />
                            </div>
                        </div>
                        <x-form.input name="reason" label="Why are the goods going back?" required placeholder="e.g. faulty batch, wrong model, not as ordered" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <span class="text-sm text-red-600" x-show="tooMany" x-cloak>One of the quantities is more than can still be sent back.</span>
                    <a href="{{ $purchase ? route('purchases.show', $purchase) : route('purchase-returns.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="invalid" data-loading-text="Saving…">Record the return</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
