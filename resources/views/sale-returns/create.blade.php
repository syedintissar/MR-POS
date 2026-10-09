@php
    use App\Support\Decimal;
    use App\Support\Money;

    $lineData = $sale ? $sale->items->map(function ($i) use ($returned) {
        $given = $returned[$i->id]['value'] ?? '0.00';
        return [
            'id' => $i->id, 'sold' => Decimal::trim((string) $i->quantity), 'done' => Decimal::trim((string) $i->returned_quantity),
            'left' => Decimal::trim(Decimal::sub((string) $i->quantity, (string) $i->returned_quantity, 3)),
            'value' => Decimal::add((string) $i->net_amount, (string) $i->tax_amount, 2), 'given' => (string) $given,
            'stock' => (bool) $i->product?->isStockItem(),
        ];
    })->values() : collect();
    $oldLines = old('lines', []);
@endphp
<x-layouts.app title="Take goods back">
    <div class="mx-auto max-w-5xl space-y-5">
        @if(! $sale)
            <form method="GET" class="card card-body max-w-xl space-y-3">
                <h2 class="card-title">Which invoice are the goods from?</h2>
                <p class="text-sm text-slate-600">Type the invoice number printed on the customer's receipt (for example INV-000123). Returns are always made against an invoice, so the customer gets back exactly what they paid for those items.</p>
                <x-form.input name="invoice" label="Invoice number" placeholder="INV-000123" autofocus required />
                <div><button class="btn btn-primary">Find invoice</button></div>
            </form>
        @else
            <script>
                window.returnForm = function (lines, due, canCredit, canRefund, old) {
                    const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
                    const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
                    const state = {};
                    lines.forEach((l) => { const o = old[l.id] || {}; state[l.id] = { qty: o.quantity || '', condition: o.condition || 'resellable', restock: o.restock !== undefined ? !!o.restock : (l.stock && (o.condition || 'resellable') === 'resellable') }; });
                    return {
                        lines, state, due, canCredit, canRefund, remainder: (!canCredit && canRefund) ? 'refund' : '',
                        setCondition(l, c) { this.state[l.id].condition = c; this.state[l.id].restock = l.stock && c === 'resellable'; },
                        lineValue(l) {
                            const q = num(this.state[l.id].qty);
                            if (q <= 0) { return 0; }
                            return q >= num(l.left) ? r2(num(l.value) - num(l.given)) : r2(num(l.value) * q / num(l.sold));
                        },
                        get total() { return r2(this.lines.reduce((s, l) => s + this.lineValue(l), 0)); },
                        get applied() { return Math.min(this.total, num(this.due)); },
                        get rest() { return r2(this.total - this.applied); },
                        get tooMany() { return this.lines.some((l) => num(this.state[l.id].qty) > num(l.left) + 0.0005); },
                        get invalid() { return this.total <= 0 || this.tooMany || (this.rest > 0 && this.remainder === ''); },
                        money(v) { return num(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                    };
                };
            </script>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invoice</div><div class="mt-1 font-mono text-lg font-semibold text-slate-900">{{ $sale->invoice_no }}</div>
                    <div class="text-xs text-slate-500">{{ $sale->sale_date->format('d M Y, H:i') }} · <a href="{{ route('sale-returns.create') }}">choose another</a></div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ $sale->customer->is_walk_in ? 'Walk-in customer' : $sale->customer->name }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invoice total / still owed</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::withSymbol($sale->grand_total) }}</div>
                    <div class="text-xs {{ (float) $sale->due_amount > 0 ? 'text-amber-700' : 'text-slate-500' }}">{{ (float) $sale->due_amount > 0 && ! $sale->customer->is_walk_in ? 'still owes '.Money::format($sale->due_amount) : 'fully paid' }}</div></div>
            </div>

            <form method="POST" action="{{ route('sale-returns.store', $sale) }}" class="space-y-5"
                  x-data="returnForm(@js($lineData), @js($sale->customer->is_walk_in ? '0' : (string) $sale->due_amount), @js($storeCredit && ! $sale->customer->is_walk_in), @js($canRefund), @js($oldLines))">
                @csrf

                <div class="card">
                    <div class="card-header"><h2 class="card-title">What is coming back?</h2></div>
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

                <div class="card">
                    <div class="card-header"><h2 class="card-title">What does the customer get?</h2></div>
                    <div class="card-body space-y-3">
                        <div class="grid gap-3 sm:grid-cols-3 text-sm">
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Value of the goods</div><div class="text-lg font-semibold" x-text="money(total)"></div></div>
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Reduces what they owe</div><div class="text-lg font-semibold" x-text="money(applied)"></div></div>
                            <div class="rounded-md bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-500">Left to hand back</div><div class="text-lg font-semibold" :class="rest > 0 ? 'text-amber-700' : ''" x-text="money(rest)"></div></div>
                        </div>

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

                        <x-form.input name="reason" label="Why is it coming back?" required placeholder="e.g. wrong model, faulty, customer changed mind" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <span class="text-sm text-red-600" x-show="tooMany" x-cloak>One of the quantities is more than can still be returned.</span>
                    <a href="{{ route('sales.show', $sale) }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="invalid" data-loading-text="Saving…">Record the return</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
