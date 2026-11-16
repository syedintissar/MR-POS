@php
    use App\Support\Decimal;
    use App\Support\Money;
@endphp
<x-layouts.app :title="'Quotation '.$quotation->quotation_no">
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="card card-body flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold text-slate-900">{{ $quotation->quotation_no }}</h1>
                    @if($quotation->isExpired())<span class="badge badge-amber text-sm">Expired</span>
                    @elseif($quotation->status === 'open')<span class="badge badge-blue text-sm">Open</span>
                    @elseif($quotation->status === 'accepted')<span class="badge badge-green text-sm">Accepted</span>
                    @else<span class="badge badge-red text-sm">Cancelled</span>@endif
                </div>
                <div class="mt-1 text-sm text-slate-600">
                    For <strong>@if($quotation->customer && ! $quotation->customer->trashed())@can('customers.view')<a href="{{ route('customers.show', $quotation->customer_id) }}">{{ $quotation->customer_name }}</a>@else{{ $quotation->customer_name }}@endcan @else{{ $quotation->customer_name }}@endif</strong>
                    @if($quotation->customer_phone) · {{ $quotation->customer_phone }}@endif
                </div>
                <div class="text-sm text-slate-600">{{ $quotation->quote_date->format('d M Y') }}@if($quotation->valid_until) · valid until {{ $quotation->valid_until->format('d M Y') }}@endif @if($quotation->creator) · by {{ $quotation->creator->name }}@endif</div>
                @if($quotation->sale)
                    <div class="mt-1 text-sm text-emerald-700">Sold as @canany(['sales.view_all', 'sales.view_own'])<a href="{{ route('sales.show', $quotation->sale_id) }}">{{ $quotation->sale->invoice_no }}</a>@else{{ $quotation->sale->invoice_no }}@endcanany on {{ $quotation->accepted_at?->format('d M Y') }}</div>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($canSell)<a href="{{ route('pos.index', ['quotation' => $quotation->id]) }}" class="btn btn-primary btn-sm">Customer accepted: open in the till</a>@endif
                <a href="{{ route('quotations.print', $quotation) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Print (A4)</a>
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" data-no-loading title="Opens WhatsApp with the quotation typed in (needs internet at that moment)">Send on WhatsApp</a>
                @if($canChange)
                    <a href="{{ route('quotations.edit', $quotation) }}" class="btn btn-secondary btn-sm">Edit</a>
                    <form method="POST" action="{{ route('quotations.cancel', $quotation) }}" data-confirm="Cancel {{ $quotation->quotation_no }}? It is kept for the record." data-confirm-label="Cancel quotation" data-danger="1">
                        @csrf<button class="btn btn-secondary btn-sm text-red-600">Cancel quotation</button>
                    </form>
                @endif
            </div>
        </div>

        @if($quotation->isOpen() && $typedLines)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ $typedLines }} item(s) were typed in and are not in the product list. They cannot be loaded into the till: add them as products and edit the quotation, or add them at the till by hand.
            </div>
        @endif

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Item</th><th class="text-right">Quantity</th><th class="text-right">Price each</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                @foreach($quotation->items as $i)
                    <tr>
                        <td>{{ $i->description }}@if($i->product)<div class="font-mono text-xs text-slate-500">{{ $i->product->sku }}</div>@else <span class="badge badge-gray">typed</span>@endif</td>
                        <td class="text-right">{{ Decimal::trim((string) $i->quantity) }} @if($i->product)<span class="text-xs text-slate-400">{{ $i->product->unit?->short_name }}</span>@endif</td>
                        <td class="text-right">{{ Money::format($i->unit_price) }}</td>
                        <td class="text-right font-medium">{{ Money::format($i->line_total) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    @if((float) $quotation->discount_amount)
                        <tr><td colspan="3" class="px-4 py-1 text-right text-slate-500">Items</td><td class="px-4 py-1 text-right">{{ Money::format($quotation->subtotal) }}</td></tr>
                        <tr><td colspan="3" class="px-4 py-1 text-right text-slate-500">Discount</td><td class="px-4 py-1 text-right">− {{ Money::format($quotation->discount_amount) }}</td></tr>
                    @endif
                    <tr class="bg-slate-50"><td colspan="3" class="px-4 py-2 text-right font-semibold">Total</td><td class="px-4 py-2 text-right text-base font-semibold">{{ Money::withSymbol($quotation->grand_total) }}</td></tr>
                </tfoot>
            </table>
        </div>

        @if($quotation->notes)<div class="card card-body text-sm text-slate-700"><div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Terms and notes</div>{!! nl2br(e($quotation->notes)) !!}</div>@endif

        <p class="text-xs text-slate-500">A quotation does not change the stock or anybody's account. It becomes a sale when it is opened in the till and completed there.</p>
    </div>
</x-layouts.app>
