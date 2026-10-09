@php
    use App\Support\Decimal;
    use App\Support\Money;
@endphp
<x-layouts.app :title="$return->return_no">
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $return->return_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ Money::withSymbol($return->total) }} credit from {{ $return->supplier->name }}</h2>
                <div class="mt-1 text-sm text-slate-500">
                    {{ $return->return_date->format('d M Y, H:i') }}
                    @if($return->purchase) · against <a href="{{ route('purchases.show', $return->purchase_id) }}">{{ $return->purchase->purchase_no }}</a>@else · without a purchase @endif
                    · entered by {{ $return->creator?->name ?? 'system' }}
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($return->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@else<span class="badge badge-green text-sm">Recorded</span>@endif
                <a href="{{ route('purchase-returns.print', $return) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Print return note</a>
            </div>
        </div>

        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reason</div><p class="mt-1 text-sm text-slate-800">{{ $return->reason }}</p></div>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Product</th><th class="text-right">Qty</th><th class="text-right">@can('products.view_cost') Left stock at @endcan</th><th class="text-right">Credit</th></tr></thead>
                <tbody>
                @foreach($return->items as $row)
                    <tr>
                        <td><a href="{{ route('products.show', $row->product_id) }}" class="font-medium text-slate-900">{{ $row->product->name }}</a><div class="font-mono text-xs text-slate-500">{{ $row->product->sku }}</div></td>
                        <td class="text-right">{{ Decimal::trim((string) $row->quantity) }}</td>
                        <td class="text-right text-slate-500">@can('products.view_cost') {{ Money::format($row->unit_cost, 2) }} each @endcan</td>
                        <td class="text-right font-medium">{{ Money::format($row->line_total) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card card-body space-y-1 text-sm">
            @if((float) $return->tax_amount)
                <div class="flex justify-between"><span class="text-slate-600">Goods</span><span>{{ Money::format($return->subtotal) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-600">Tax</span><span>{{ Money::format($return->tax_amount) }}</span></div>
            @endif
            <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Credit from the supplier</span><span>{{ Money::withSymbol($return->total) }}</span></div>
            @if($return->refundPayment)
                <div class="flex justify-between"><span class="text-slate-600">Money received back ({{ $return->refundPayment->method->name }}, {{ $return->refundPayment->payment_no }})</span><span>{{ Money::format($return->refundPayment->amount) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-600">Taken off the bill</span><span>{{ Money::format(Decimal::sub((string) $return->total, (string) $return->refundPayment->amount, 2)) }}</span></div>
            @else
                <p class="text-slate-500">The credit was taken off the bill and, where it was more than the bill, stays on the supplier's account.</p>
            @endif
        </div>

        @if($return->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Cancelled by {{ $return->voider?->name }} on {{ $return->voided_at->format('d M Y, H:i') }}: “{{ $return->void_reason }}”.
                The goods came back into stock and the supplier's account and the bill were put back as they were; the original entries stay in the history.
            </div>
        @else
            @can('purchases.void')
                <form method="POST" action="{{ route('purchase-returns.void', $return) }}" class="card card-body space-y-3"
                      data-confirm="Cancel return {{ $return->return_no }}? The goods come back into stock and the supplier's account is put back." data-confirm-label="Cancel return" data-danger="1">
                    @csrf
                    <h3 class="card-title">Recorded by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling puts everything back as it was before the return, including a refund the supplier paid. Nothing is deleted.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this return</button></div>
                </form>
            @endcan
        @endif

        <a href="{{ route('purchase-returns.index') }}" class="inline-block text-sm">← All purchase returns</a>
    </div>
</x-layouts.app>
