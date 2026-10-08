@php
    use App\Support\Decimal;
    use App\Support\Money;
@endphp
<x-layouts.app :title="'Invoice '.$sale->invoice_no">
    <div class="mx-auto max-w-5xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $sale->invoice_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ Money::withSymbol($sale->grand_total) }}</h2>
                <div class="mt-1 text-sm text-slate-500">
                    {{ $sale->sale_date->format('d M Y, H:i') }} · sold by {{ $sale->cashier?->name ?? 'system' }}
                    · @if($sale->customer->is_walk_in)walk-in customer @else @can('customers.view')<a href="{{ route('customers.show', $sale->customer_id) }}">{{ $sale->customer->name }}</a>@else{{ $sale->customer->name }}@endcan @endif
                    @if($sale->price_tier === 'wholesale') · <span class="badge badge-blue">wholesale prices</span>@endif
                    @if($sale->approver) · approved by {{ $sale->approver->name }}@endif
                </div>
                @if($sale->notes)<p class="mt-2 text-sm text-slate-700">{{ $sale->notes }}</p>@endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($sale->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@elseif($sale->payment_status === 'paid')<span class="badge badge-green text-sm">Paid</span>@else<span class="badge badge-amber text-sm">{{ $sale->payment_status }}</span>@endif
                @if(! $sale->isVoided() && (float) $sale->due_amount > 0 && ! $sale->customer->is_walk_in)@can('customer_payments.create')<a href="{{ route('customer-payments.create', ['customer' => $sale->customer_id, 'sale' => $sale->id]) }}" class="btn btn-primary btn-sm">Collect payment</a>@endcan @endif
                @if($canReprint)
                    <a href="{{ route('sales.reprint', [$sale, 'thermal']) }}" target="_blank" class="btn btn-secondary btn-sm">Print receipt (copy)</a>
                    <a href="{{ route('sales.reprint', [$sale, 'a4']) }}" target="_blank" class="btn btn-secondary btn-sm">Print A4 invoice (copy)</a>
                    <a href="{{ route('sales.pdf', $sale) }}" class="btn btn-secondary btn-sm">Download PDF</a>
                @endif
            </div>
        </div>

        @if($sale->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Cancelled by {{ $sale->voider?->name }} on {{ $sale->voided_at->format('d M Y, H:i') }}: “{{ $sale->void_reason }}”.
                The goods went back into stock and the payments were cancelled; the original entries stay in the stock card and the payment list.
            </div>
        @endif

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Product</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Discount</th>@if((float) $sale->tax_total)<th class="text-right">Tax</th>@endif<th class="text-right">Total</th>@if($showCost)<th class="text-right">Cost</th><th class="text-right">Profit</th>@endif</tr></thead>
                <tbody>
                @foreach($sale->items as $item)
                    @php($discount = bcadd((string) $item->discount_amount, (string) $item->invoice_discount_share, 2))
                    <tr>
                        <td><a href="{{ route('products.show', $item->product_id) }}" class="font-medium text-slate-900">{{ $item->product_name }}</a>
                            <div class="font-mono text-xs text-slate-500">{{ $item->sku }}@if($item->serial_number) · S/N {{ $item->serial_number }}@endif @if($item->warranty_until) · warranty to {{ $item->warranty_until->format('d M Y') }}@endif</div>
                            @if(Decimal::cmp((string) $item->unit_price, (string) $item->list_price) !== 0)<div class="text-xs text-amber-700">price changed from {{ Money::format($item->list_price) }}</div>@endif</td>
                        <td class="text-right">{{ Decimal::trim((string) $item->quantity) }}</td>
                        <td class="text-right">{{ Money::format($item->unit_price) }}</td>
                        <td class="text-right text-slate-500">{{ (float) $discount ? Money::format($discount) : '' }}</td>
                        @if((float) $sale->tax_total)<td class="text-right text-slate-500">{{ (float) $item->tax_amount ? Money::format($item->tax_amount) : '' }}</td>@endif
                        <td class="text-right font-medium">{{ Money::format($item->line_total) }}</td>
                        @if($showCost)
                            @php($lineCost = bcmul((string) $item->quantity, (string) $item->unit_cost, 2))
                            <td class="text-right text-slate-500">{{ Money::format($lineCost) }}</td>
                            <td class="text-right {{ bccomp((string) $item->net_amount, $lineCost, 2) < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ Money::format(bcsub((string) $item->net_amount, $lineCost, 2)) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="card card-body space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-slate-600">Items</span><span>{{ Money::format($sale->items_total) }}</span></div>
                @if((float) $sale->line_discount_total)<div class="flex justify-between"><span class="text-slate-600">Line discounts</span><span>− {{ Money::format($sale->line_discount_total) }}</span></div>@endif
                @if((float) $sale->invoice_discount_amount)<div class="flex justify-between"><span class="text-slate-600">Invoice discount{{ $sale->invoice_discount_percent ? ' ('.Decimal::trim((string) $sale->invoice_discount_percent).'%)' : '' }}</span><span>− {{ Money::format($sale->invoice_discount_amount) }}</span></div>@endif
                @if((float) $sale->tax_total)<div class="flex justify-between"><span class="text-slate-600">{{ app(\App\Services\SettingsService::class)->string('tax.label') }}{{ $sale->tax_inclusive ? ' (included in prices)' : '' }}</span><span>{{ Money::format($sale->tax_total) }}</span></div>@endif
                @if((float) $sale->round_off)<div class="flex justify-between"><span class="text-slate-600">Round-off</span><span>{{ (float) $sale->round_off > 0 ? '+' : '−' }} {{ Money::format(abs((float) $sale->round_off)) }}</span></div>@endif
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Total</span><span>{{ Money::withSymbol($sale->grand_total) }}</span></div>
                @if(! $sale->isVoided())
                    <div class="flex justify-between text-emerald-700"><span>Paid</span><span>{{ Money::format($sale->paid_amount) }}</span></div>
                    @if((float) $sale->change_amount)<div class="flex justify-between text-slate-600"><span>Cash handed over {{ Money::format($sale->amount_tendered) }}, change given</span><span>{{ Money::format($sale->change_amount) }}</span></div>@endif
                    @if((float) $sale->due_amount)<div class="flex justify-between font-semibold text-amber-700"><span>Still to pay</span><span>{{ Money::format($sale->due_amount) }}</span></div>@endif
                @endif
                @if($showCost)
                    <div class="mt-2 border-t border-dashed border-slate-300 pt-2 text-xs text-slate-500">
                        <div class="flex justify-between"><span>Cost of the goods</span><span>{{ Money::format($cost) }}</span></div>
                        <div class="flex justify-between font-semibold text-slate-700"><span>Gross profit (before tax)</span><span>{{ Money::format(bcsub((string) $sale->items->sum('net_amount'), $cost, 2)) }}</span></div>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Payments</h2></div>
                @if($sale->allocations->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">{{ (float) $sale->grand_total ? 'No payment is recorded for this sale.' : 'This sale was free.' }}</p>
                @else
                    <table class="table">
                        <thead><tr><th>Receipt</th><th>Method</th><th>Reference</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                        @foreach($sale->allocations as $a)
                            <tr class="{{ $a->payment->isVoided() ? 'text-slate-400 line-through' : '' }}">
                                <td class="font-mono text-xs">{{ $a->payment->receipt_no }}</td>
                                <td>{{ $a->payment->method->name }}@if($a->payment->isVoided()) <span class="badge badge-red no-underline">cancelled</span>@endif</td>
                                <td class="text-slate-600">{{ $a->payment->reference_no }}</td>
                                <td class="text-right">{{ Money::format($a->amount) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @if($canVoid && ! $sale->isVoided())
            <form method="POST" action="{{ route('sales.void', $sale) }}" class="card card-body space-y-3"
                  data-confirm="Cancel invoice {{ $sale->invoice_no }}? The goods go back into stock and the payments are cancelled." data-confirm-label="Cancel invoice" data-danger="1">
                @csrf
                <h3 class="card-title">Rung up by mistake?</h3>
                <p class="text-sm text-slate-600">Cancelling writes the opposite stock entries and cancels the payments; nothing is deleted. Managers can cancel within {{ $voidWindowHours }} hours of the sale, administrators at any time.
                    After that, or if goods were returned, use a sales return.</p>
                <x-form.input name="reason" label="Reason for cancelling" required />
                <div><button class="btn btn-danger">Cancel this invoice</button></div>
            </form>
        @endif

        <a href="{{ route('sales.index') }}" class="inline-block text-sm">← All sales</a>
    </div>
</x-layouts.app>
