@php
    use App\Support\Decimal;
    use App\Support\Money;
@endphp
<x-layouts.app :title="$return->return_no">
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $return->return_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ Money::withSymbol($return->total) }} returned</h2>
                <div class="mt-1 text-sm text-slate-500">
                    {{ $return->return_date->format('d M Y, H:i') }} · against <a href="{{ route('sales.show', $return->sale_id) }}">{{ $return->sale->invoice_no }}</a>
                    · {{ $return->customer->is_walk_in ? 'walk-in customer' : $return->customer->name }} · taken by {{ $return->creator?->name ?? 'system' }}
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($return->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@else<span class="badge badge-green text-sm">Recorded</span>@endif
                @if($receiptUrl && ! $return->isVoided())<a href="{{ $receiptUrl }}" target="_blank" class="btn btn-primary btn-sm" data-no-loading>Print the return receipt</a>@endif
                @if($saleReceiptUrl)<a href="{{ $saleReceiptUrl }}" target="_blank" class="btn btn-primary btn-sm" data-no-loading>Print the new invoice</a>@endif
                @can('sales.reprint')<a href="{{ route('sale-returns.reprint', $return) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Print receipt (copy)</a>@endcan
            </div>
        </div>

        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reason</div><p class="mt-1 text-sm text-slate-800">{{ $return->reason }}</p>
            @if($return->period_exception_reason)<p class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">Allowed after the return period: {{ $return->period_exception_reason }}</p>@endif
            @if($return->exchangeSale)<p class="mt-2 text-sm text-slate-700">Exchanged for <a href="{{ route('sales.show', $return->exchange_sale_id) }}" class="font-mono">{{ $return->exchangeSale->invoice_no }}</a> ({{ Money::withSymbol($return->exchangeSale->grand_total) }}).</p>@endif
        </div>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Item</th><th class="text-right">Qty</th><th>Condition</th><th>Stock</th><th class="text-right">Value</th></tr></thead>
                <tbody>
                @foreach($return->items as $row)
                    <tr>
                        <td><a href="{{ route('products.show', $row->product_id) }}" class="font-medium text-slate-900">{{ $row->saleItem?->product_name ?? $row->product->name }}</a>
                            <div class="font-mono text-xs text-slate-500">{{ $row->product->sku }}</div></td>
                        <td class="text-right">{{ Decimal::trim((string) $row->quantity) }}</td>
                        <td>{{ \App\Models\SaleReturnItem::CONDITIONS[$row->item_condition] ?? $row->item_condition }}</td>
                        <td>{!! $row->restock ? '<span class="badge badge-green">put back</span>' : '<span class="badge badge-amber">written off</span>' !!}</td>
                        <td class="text-right font-medium">{{ Money::format($row->line_total) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card card-body space-y-1 text-sm">
            @if((float) $return->tax_total)
                <div class="flex justify-between"><span class="text-slate-600">Goods</span><span>{{ Money::format($return->net_total) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-600">Tax</span><span>{{ Money::format($return->tax_total) }}</span></div>
            @endif
            <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Value of the return</span><span>{{ Money::withSymbol($return->total) }}</span></div>
            @if((float) $return->restocking_fee)
                <div class="flex justify-between"><span class="text-slate-600">Restocking fee kept by the shop</span><span>− {{ Money::format($return->restocking_fee) }}</span></div>
                <div class="flex justify-between font-medium"><span class="text-slate-600">Credited to the customer</span><span>{{ Money::format(Decimal::sub((string) $return->total, (string) $return->restocking_fee, 2)) }}</span></div>
            @endif
            @if((float) $return->applied_to_due)<div class="flex justify-between"><span class="text-slate-600">Taken off what the customer owed on the invoice</span><span>{{ Money::format($return->applied_to_due) }}</span></div>@endif
            @if((float) $return->refund_amount)<div class="flex justify-between"><span class="text-slate-600">Money handed back ({{ $return->refundPayment?->method?->name }}, {{ $return->refundPayment?->receipt_no }})</span><span>{{ Money::format($return->refund_amount) }}</span></div>@endif
            @if((float) $return->store_credit_amount)<div class="flex justify-between"><span class="text-slate-600">Kept as store credit ({{ $return->creditPayment?->receipt_no }})</span><span>{{ Money::format($return->store_credit_amount) }}</span></div>@endif
        </div>

        @if($return->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Cancelled by {{ $return->voider?->name }} on {{ $return->voided_at->format('d M Y, H:i') }}: “{{ $return->void_reason }}”.
                The stock, the invoice and the customer's account were put back as they were; the original entries stay in the history.
            </div>
        @else
            @can('sales.void')
                <form method="POST" action="{{ route('sale-returns.void', $return) }}" class="card card-body space-y-3"
                      data-confirm="Cancel return {{ $return->return_no }}? The goods leave the stock again and the invoice owes what it did before." data-confirm-label="Cancel return" data-danger="1">
                    @csrf
                    <h3 class="card-title">Recorded by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling puts everything back as it was before the return. It is refused when the goods have been sold again or the store credit has been spent. Nothing is deleted.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this return</button></div>
                </form>
            @endcan
        @endif

        <a href="{{ route('sale-returns.index') }}" class="inline-block text-sm">← All returns</a>
    </div>
</x-layouts.app>
