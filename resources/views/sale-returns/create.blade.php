@php
    use App\Support\Decimal;
    use App\Support\Money;

    $lineData = $sale ? $sale->items->map(function ($i) use ($returned) {
        return [
            'id' => $i->id, 'sold' => Decimal::trim((string) $i->quantity), 'done' => Decimal::trim((string) $i->returned_quantity),
            'left' => Decimal::trim(Decimal::sub((string) $i->quantity, (string) $i->returned_quantity, 3)),
            'value' => Decimal::add((string) $i->net_amount, (string) $i->tax_amount, 2), 'net' => (string) $i->net_amount,
            'given' => (string) ($returned[$i->id]['value'] ?? '0.00'), 'givenNet' => (string) ($returned[$i->id]['net'] ?? '0.00'),
            'stock' => (bool) $i->product?->isStockItem(),
        ];
    })->values() : collect();
    $walkIn = $sale?->customer->is_walk_in ?? true;
    $config = $sale ? [
        'lines' => $lineData, 'due' => $walkIn ? '0' : (string) $sale->due_amount, 'canCredit' => $storeCredit && ! $walkIn, 'canRefund' => $canRefund, 'walkIn' => $walkIn,
        'old' => old('lines', []), 'fee' => $fee, 'blocked' => $period['overdue'] && ! $period['canOverride'], 'exchange' => $exchange,
        'repl' => array_values(old('replacement', [])), 'lookupUrl' => route('sale-returns.products'), 'mode' => old('mode', 'net'),
    ] : [];
@endphp
<x-layouts.app :title="$exchange ? 'Exchange' : 'Take goods back'">
    <div class="mx-auto max-w-5xl space-y-5">
        @if(! $sale)
            <form method="GET" class="card card-body max-w-xl space-y-3">
                <h2 class="card-title">Which invoice are the goods from?</h2>
                <p class="text-sm text-slate-600">Type the invoice number printed on the customer's receipt (for example INV-000123). Returns and exchanges are always made against an invoice, so the customer gets back exactly what they paid for those items.</p>
                <x-form.input name="invoice" label="Invoice number" placeholder="INV-000123" autofocus required />
                <div><button class="btn btn-primary">Find invoice</button></div>
            </form>
        @else
            <script>
                window.returnForm = function (c) {
                    const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
                    const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
                    const state = {};
                    c.lines.forEach((l) => { const o = c.old[l.id] || {}; state[l.id] = { qty: o.quantity || '', condition: o.condition || 'resellable', restock: o.restock !== undefined ? !!o.restock : (l.stock && (o.condition || 'resellable') === 'resellable') }; });
                    return {
                        lines: c.lines, state, due: c.due, canCredit: c.canCredit, canRefund: c.canRefund, walkIn: c.walkIn, fee: c.fee, blocked: c.blocked, exchange: c.exchange,
                        remainder: (!c.canCredit && c.canRefund) ? 'refund' : '', mode: c.walkIn ? 'refund' : c.mode, waive: false, repl: c.repl.map((r) => Object.assign({ name: '', price: '0' }, r)), query: '', found: [],
                        setCondition(l, cond) { this.state[l.id].condition = cond; this.state[l.id].restock = l.stock && cond === 'resellable'; },
                        // the value of the units asked for: their share of the line, the last units taking what is left (as the server does it)
                        share(l, q, whole, given) { return q >= num(l.left) ? r2(num(whole) - num(given)) : r2(num(whole) * q / num(l.sold)); },
                        lineValue(l) { const q = num(this.state[l.id].qty); return q <= 0 ? 0 : this.share(l, q, l.value, l.given); },
                        lineNet(l) { const q = num(this.state[l.id].qty); return q <= 0 ? 0 : this.share(l, q, l.net, l.givenNet); },
                        get total() { return r2(this.lines.reduce((s, l) => s + this.lineValue(l), 0)); },
                        get net() { return r2(this.lines.reduce((s, l) => s + this.lineNet(l), 0)); },
                        get feeAmount() { return this.fee.enabled && !this.waive ? Math.min(this.net, r2(this.net * num(this.fee.percent) / 100)) : 0; },
                        get credited() { return r2(this.total - this.feeAmount); },
                        get applied() { return Math.min(this.credited, num(this.due)); },
                        get rest() { return r2(this.credited - this.applied); },
                        get replTotal() { return r2(this.repl.reduce((s, r) => s + num(r.price) * num(r.quantity), 0)); },
                        get tooMany() { return this.lines.some((l) => num(this.state[l.id].qty) > num(l.left) + 0.0005); },
                        get invalid() { return this.total <= 0 || this.tooMany || this.blocked || (this.exchange ? this.repl.length === 0 || this.repl.some((r) => num(r.quantity) <= 0) : (this.rest > 0 && this.remainder === '')); },
                        async search() {
                            if (this.query.trim().length < 1) { this.found = []; return; }
                            try { const r = await fetch(c.lookupUrl + '?q=' + encodeURIComponent(this.query.trim()), { headers: { Accept: 'application/json' } }); this.found = r.ok ? await r.json() : []; } catch (e) { this.found = []; }
                        },
                        add(p) { const same = this.repl.find((r) => r.product_id === p.id); if (same) { same.quantity = String(num(same.quantity) + 1); } else { this.repl.push({ product_id: p.id, name: p.name, sku: p.sku, price: p.price, quantity: '1' }); } this.found = []; this.query = ''; },
                        money(v) { return num(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                    };
                };
            </script>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invoice</div><div class="mt-1 font-mono text-lg font-semibold text-slate-900">{{ $sale->invoice_no }}</div>
                    <div class="text-xs text-slate-500">{{ $sale->sale_date->format('d M Y, H:i') }} · <a href="{{ route('sale-returns.create') }}">choose another</a></div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ $walkIn ? 'Walk-in customer' : $sale->customer->name }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invoice total / still owed</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::withSymbol($sale->grand_total) }}</div>
                    <div class="text-xs {{ (float) $sale->due_amount > 0 ? 'text-amber-700' : 'text-slate-500' }}">{{ (float) $sale->due_amount > 0 && ! $walkIn ? 'still owes '.Money::format($sale->due_amount) : 'fully paid' }}</div></div>
            </div>

            @if($period['overdue'])
                <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    This invoice is <strong>{{ $period['age'] }} days old</strong>: goods are normally taken back within {{ $period['days'] }} days.
                    @if($period['canOverride']) You may allow this one as an exception: say why below (it is recorded and audited). @else Ask a manager to allow this return. @endif
                </div>
            @endif

            <form method="POST" action="{{ $exchange ? route('sale-returns.exchange', $sale) : route('sale-returns.store', $sale) }}" class="space-y-5" x-data="returnForm(@js($config))">
                @csrf
                @if($exchange)<input type="hidden" name="checkout_key" value="{{ old('checkout_key', (string) \Illuminate\Support\Str::uuid()) }}">@endif

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ $exchange ? 'Exchange: 1. What is coming back?' : 'What is coming back?' }}</h2>
                        @if($canSell)<a href="{{ route('sale-returns.create', ['sale' => $sale->id] + ($exchange ? [] : ['exchange' => 1])) }}" class="text-xs">{{ $exchange ? 'Just a return, no new items' : 'Exchange for other items instead' }}</a>@endif
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Item</th><th class="text-right">Sold</th><th class="text-right">Already returned</th><th class="text-right">Bring back</th><th>Condition</th><th>Back on the shelf?</th><th class="text-right">Value</th></tr></thead>
                            <tbody>
                            @foreach($sale->items as $item)
                                <tr x-data="{ l: lines.find(x => x.id === {{ $item->id }}) }">
                                    <td><span class="font-medium text-slate-900">{{ $item->product_name }}</span><div class="font-mono text-xs text-slate-500">{{ $item->sku }}@if($item->serial_number) · S/N {{ $item->serial_number }}@endif</div>
                                        @if($item->warranty_until)<div class="mt-0.5 text-xs {{ $item->warranty_until->isFuture() ? 'font-medium text-emerald-700' : 'text-slate-500' }}">{{ $item->warranty_until->isFuture() ? 'Under warranty until' : 'Warranty ended' }} {{ $item->warranty_until->format('d M Y') }}</div>@elseif($item->serial_number)<div class="mt-0.5 text-xs text-slate-500">Has a serial number, no warranty recorded</div>@endif</td>
                                    <td class="text-right">{{ Decimal::trim((string) $item->quantity) }}</td>
                                    <td class="text-right text-slate-500">{{ (float) $item->returned_quantity ? Decimal::trim((string) $item->returned_quantity) : '' }}</td>
                                    <td class="text-right">
                                        @if(Decimal::cmp((string) $item->quantity, (string) $item->returned_quantity) > 0)
                                            <input type="number" step="0.001" min="0" max="{{ Decimal::sub((string) $item->quantity, (string) $item->returned_quantity, 3) }}" name="lines[{{ $item->id }}][quantity]" x-model="state[{{ $item->id }}].qty" class="input w-24 text-right" aria-label="Quantity of {{ $item->product_name }} to bring back">
                                        @else
                                            <span class="text-slate-400">all returned</span>
                                        @endif
                                    </td>
                                    <td>
                                        <select name="lines[{{ $item->id }}][condition]" class="input w-32" :value="state[{{ $item->id }}].condition" @change="setCondition(l, $event.target.value)" aria-label="Condition of {{ $item->product_name }}">
                                            @foreach($conditions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <template x-if="l.stock"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="lines[{{ $item->id }}][restock]" value="1" x-model="state[{{ $item->id }}].restock" class="h-4 w-4 rounded border-slate-300 text-brand-600"> put back in stock</label></template>
                                        <template x-if="! l.stock"><span class="text-xs text-slate-400">no stock to put back</span></template>
                                    </td>
                                    <td class="text-right font-medium" x-text="lineValue(l) > 0 ? money(lineValue(l)) : ''"></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @error('lines')<p class="error-text px-5 pb-3">{{ $message }}</p>@enderror
                    <p class="px-5 pb-4 text-xs text-slate-500">Each item is valued at what the customer actually paid for it, after any discount; the last units of a line take whatever is left, so the pieces always add up. Items that are not put back in stock are written off at their cost.</p>
                </div>

                @if($exchange)
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Exchange: 2. What does the customer take instead?</h2></div>
                        <div class="card-body space-y-3">
                            <div class="relative max-w-md">
                                <label class="label" for="repl_search">Find the new item</label>
                                <input id="repl_search" type="search" x-model="query" @input.debounce.250ms="search()" placeholder="Name, SKU or barcode" class="input" autocomplete="off">
                                <div x-show="found.length" x-cloak class="absolute z-10 mt-1 w-full rounded-md border border-slate-200 bg-white shadow-lg">
                                    <template x-for="p in found" :key="p.id"><button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50" data-no-loading @click="add(p)"><span x-text="p.name"></span> <span class="font-mono text-xs text-slate-400" x-text="p.sku"></span> <span class="text-xs text-slate-500">· <span x-text="money(p.price)"></span></span></button></template>
                                </div>
                            </div>
                            <p class="text-sm text-slate-500" x-show="repl.length === 0">Search for the items the customer takes. They are sold at the normal price (a manager changes a price on the till, not here).</p>
                            <table class="table" x-show="repl.length" x-cloak>
                                <thead><tr><th>Item</th><th class="text-right">Price</th><th class="text-right">Quantity</th><th class="text-right">About</th><th></th></tr></thead>
                                <tbody>
                                <template x-for="(r, i) in repl" :key="r.product_id">
                                    <tr>
                                        <td><span class="font-medium" x-text="r.name"></span><div class="font-mono text-xs text-slate-500" x-text="r.sku"></div><input type="hidden" :name="'replacement[' + i + '][product_id]'" :value="r.product_id"></td>
                                        <td class="text-right text-slate-600" x-text="money(r.price)"></td>
                                        <td class="text-right"><input type="number" step="0.001" min="0.001" :name="'replacement[' + i + '][quantity]'" x-model="r.quantity" class="input w-24 text-right" required></td>
                                        <td class="text-right font-medium" x-text="money((parseFloat(r.price) || 0) * (parseFloat(r.quantity) || 0))"></td>
                                        <td class="text-right"><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="repl.splice(i, 1)">remove</button></td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                            @error('replacement')<p class="error-text">{{ $message }}</p>@enderror
                            <p class="text-xs text-slate-500" x-show="repl.length" x-cloak>The exact total (tax, rounding) is worked out when you press the button; the amounts above are the normal prices.</p>
                        </div>
                    </div>
                @endif

                <div class="card">
                    <div class="card-header"><h2 class="card-title">{{ $exchange ? 'Exchange: 3. Settle it' : 'What does the customer get?' }}</h2></div>
                    <div class="card-body space-y-3">
                        <div class="grid gap-3 text-sm sm:grid-cols-{{ $exchange ? '4' : '3' }}">
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Value of the goods</div><div class="text-lg font-semibold" x-text="money(total)"></div>
                                <div class="text-xs text-slate-500" x-show="feeAmount > 0" x-cloak>less restocking fee <span x-text="money(feeAmount)"></span></div></div>
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Reduces what they owe</div><div class="text-lg font-semibold" x-text="money(applied)"></div></div>
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">{{ $exchange ? 'Credit from the return' : 'Left to hand back' }}</div><div class="text-lg font-semibold" :class="rest > 0 ? 'text-amber-700' : ''" x-text="money(rest)"></div></div>
                            @if($exchange)<div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">New items (about)</div><div class="text-lg font-semibold" x-text="money(replTotal)"></div></div>@endif
                        </div>

                        @if($fee['enabled'])
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="waive_fee" value="1" x-model="waive" class="h-4 w-4 rounded border-slate-300 text-brand-600"> Waive the restocking fee (otherwise {{ rtrim(rtrim($fee['percent'], '0'), '.') }}% of the goods, without tax, is kept)</label>
                        @endif

                        @if($exchange)
                            <div class="space-y-3 text-sm">
                                @unless($walkIn)
                                    <div class="space-y-1">
                                        <label class="flex items-center gap-2"><input type="radio" name="mode" value="net" x-model="mode" class="h-4 w-4 text-brand-600"> Use the credit from the return to pay for the new items (the customer pays only the difference, or keeps what is left over as store credit)</label>
                                        <label class="flex items-center gap-2" x-show="canRefund"><input type="radio" name="mode" value="refund" x-model="mode" class="h-4 w-4 text-brand-600"> Hand the money for the returned goods back, and charge the new items in full</label>
                                    </div>
                                @endunless
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div><label class="label" for="pay_method_id">The customer pays by</label>
                                        <select id="pay_method_id" name="pay_method_id" class="input">@foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) old('pay_method_id') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
                                    <x-form.input name="tendered" label="Amount handed over (optional)" type="number" step="0.01" min="0" help="Leave empty for the exact amount; more than that gives change (cash)" />
                                    <div x-show="mode === 'refund'" x-cloak><label class="label" for="refund_method_id">Money for the returned goods is paid back by</label>
                                        <select id="refund_method_id" name="refund_method_id" class="input">@foreach($methods as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
                                </div>
                                <p class="rounded-md bg-slate-50 px-4 py-3" x-show="repl.length && total > 0" x-cloak>
                                    <span x-show="mode === 'net' && replTotal - rest > 0.004">The customer pays about <strong x-text="money(replTotal - rest)"></strong> more.</span>
                                    <span x-show="mode === 'net' && replTotal - rest < -0.004">The customer keeps about <strong x-text="money(rest - replTotal)"></strong> as store credit.</span>
                                    <span x-show="mode === 'net' && Math.abs(replTotal - rest) <= 0.004">An even exchange.</span>
                                    <span x-show="mode === 'refund'">The customer is paid back <strong x-text="money(rest)"></strong> for the returned goods and pays <strong x-text="money(replTotal)"></strong> for the new items: <strong x-text="money(Math.abs(replTotal - rest))"></strong> <span x-text="replTotal - rest >= 0 ? 'to collect' : 'to hand back'"></span> in all.</span>
                                </p>
                            </div>
                        @else
                            <div x-show="rest > 0" x-cloak class="space-y-2">
                                <div class="flex flex-wrap gap-4 text-sm">
                                    <label class="flex items-center gap-2" x-show="canRefund"><input type="radio" name="remainder" value="refund" x-model="remainder" class="h-4 w-4 text-brand-600"> Give the money back</label>
                                    <label class="flex items-center gap-2" x-show="canCredit"><input type="radio" name="remainder" value="credit" x-model="remainder" class="h-4 w-4 text-brand-600"> Keep it as store credit</label>
                                </div>
                                <p class="text-xs text-amber-700" x-show="! canRefund && ! canCredit">You may not pay refunds and store credit is not available for this customer: ask a manager to make this return.</p>
                                <div x-show="remainder === 'refund'" x-cloak class="max-w-xs">
                                    <label class="label" for="refund_method_id">Paid back by</label>
                                    <select id="refund_method_id" name="refund_method_id" class="input">@foreach($methods as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                                </div>
                                @error('remainder')<p class="error-text">{{ $message }}</p>@enderror
                            </div>
                        @endif

                        @if($period['overdue'] && $period['canOverride'])
                            <x-form.input name="exception_reason" :label="'Why is this return allowed after '.$period['days'].' days?'" required placeholder="e.g. faulty on delivery, customer was abroad" />
                        @endif
                        <x-form.input name="reason" label="Why is it coming back?" required placeholder="e.g. wrong model, faulty, customer changed mind" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <span class="text-sm text-red-600" x-show="tooMany" x-cloak>One of the quantities is more than can still be returned.</span>
                    <span class="text-sm text-red-600" x-show="blocked" x-cloak>The return period has passed: ask a manager.</span>
                    <a href="{{ route('sales.show', $sale) }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="invalid" data-loading-text="Saving…">{{ $exchange ? 'Do the exchange' : 'Record the return' }}</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
