@php
    use App\Support\Decimal;
    use App\Support\Money;

    $width = (int) $s->get('printing.paper_width_mm');
    $mt = $s->get('printing.margin_top_mm'); $mr = $s->get('printing.margin_right_mm'); $mb = $s->get('printing.margin_bottom_mm'); $ml = $s->get('printing.margin_left_mm');
    $font = $s->get('printing.font_size_pt');
    $shop = $s->string('shop.name') ?: config('app.name');
    $voided = $sale->isVoided();
    $taxLabel = $s->string('tax.label');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->invoice_no }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { size: {{ $width }}mm auto; margin: {{ $mt }}mm {{ $mr }}mm {{ $mb }}mm {{ $ml }}mm; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { width: {{ $width - $ml - $mr }}mm; font-family: Consolas, 'Lucida Console', 'Courier New', monospace; font-size: {{ $font }}pt; line-height: 1.25; }
        .copy { page-break-after: always; break-after: page; padding-bottom: 2mm; }
        .copy:last-child { page-break-after: auto; break-after: auto; }
        .c { text-align: center; } .r { text-align: right; } .b { font-weight: 700; }
        .shop { font-size: {{ $font + 3 }}pt; font-weight: 700; }
        .logo { max-height: 14mm; max-width: 60%; margin: 0 auto 1mm; display: block; }
        .sep { border-top: 1px dashed #000; margin: 1.5mm 0; }
        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .row > span:first-child { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .row > span:last-child { white-space: nowrap; }
        .item { margin-bottom: 1mm; }
        .small { font-size: {{ max(6, $font - 1.5) }}pt; }
        .total { font-size: {{ $font + 3 }}pt; font-weight: 700; }
        .banner { border: 2px solid #000; text-align: center; font-weight: 700; font-size: {{ $font + 3 }}pt; padding: 1mm; margin: 1.5mm 0; letter-spacing: 1px; }
        .bc { text-align: center; margin-top: 1.5mm; } .bc svg { width: 80%; height: 11mm; }
        .bar { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; padding: 8px 12px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1; }
        .bar button { padding: 4px 12px; margin-right: 8px; }
        @media print { .bar { display: none; } }
    </style>
</head>
<body>
@unless($autoprint)
    <div class="bar"><button type="button" onclick="window.print()">Print</button> {{ $sale->invoice_no }} · {{ $width }} mm receipt{{ $voided ? ' · marked VOID' : ($duplicate ? ' · marked DUPLICATE' : '') }}</div>
@endunless
@for($copy = 1; $copy <= $copies; $copy++)
<div class="copy">
    <div class="c">
        @if($logo)<img src="{{ $logo }}" alt="" class="logo">@endif
        <div class="shop">{{ $shop }}</div>
        @if($s->string('shop.address'))<div>{{ $s->string('shop.address') }}</div>@endif
        @if($s->string('shop.phone'))<div>Tel: {{ $s->string('shop.phone') }}</div>@endif
        @if($s->string('shop.ntn'))<div>NTN: {{ $s->string('shop.ntn') }}</div>@endif
        @if($s->string('receipt.header'))<div class="small">{{ $s->string('receipt.header') }}</div>@endif
    </div>

    @if($voided)<div class="banner">*** VOID ***</div>@elseif($duplicate)<div class="banner">DUPLICATE</div>@endif

    <div class="sep"></div>
    <div class="row"><span class="b">{{ (float) $sale->tax_total ? $taxLabel.' INVOICE' : 'INVOICE' }}</span><span class="b">{{ $sale->invoice_no }}</span></div>
    <div class="row"><span>{{ $sale->sale_date->format('d/m/Y') }}</span><span>{{ $sale->sale_date->format('H:i') }}</span></div>
    @if($s->bool('receipt.show_cashier') && $sale->cashier)<div>Cashier: {{ $sale->cashier->name }}</div>@endif
    @unless($sale->customer->is_walk_in)<div>Customer: {{ $sale->customer->name }}</div>@endunless
    <div class="sep"></div>

    @foreach($sale->items as $item)
        @php($disc = bcadd((string) $item->discount_amount, (string) $item->invoice_discount_share, 2))
        <div class="item">
            <div>{{ $item->product_name }}</div>
            <div class="row small"><span>{{ Decimal::trim((string) $item->quantity) }} x {{ Money::format($item->unit_price) }}</span><span class="b">{{ Money::format($item->line_total) }}</span></div>
            @if((float) $disc)<div class="small r">discount - {{ Money::format($disc) }}</div>@endif
            @if($item->serial_number)<div class="small">S/N: {{ $item->serial_number }}</div>@endif
            @if($item->warranty_until)<div class="small">Warranty until {{ $item->warranty_until->format('d/m/Y') }}</div>@endif
        </div>
    @endforeach

    <div class="sep"></div>
    @if((float) $sale->line_discount_total || (float) $sale->invoice_discount_amount || (float) $sale->tax_total || (float) $sale->round_off)
        <div class="row"><span>Subtotal</span><span>{{ Money::format($sale->items_total) }}</span></div>
        @if((float) $sale->line_discount_total)<div class="row"><span>Item discounts</span><span>- {{ Money::format($sale->line_discount_total) }}</span></div>@endif
        @if((float) $sale->invoice_discount_amount)<div class="row"><span>Discount{{ $sale->invoice_discount_percent ? ' '.Decimal::trim((string) $sale->invoice_discount_percent).'%' : '' }}</span><span>- {{ Money::format($sale->invoice_discount_amount) }}</span></div>@endif
        @if((float) $sale->tax_total)<div class="row"><span>{{ $taxLabel }}{{ $sale->tax_inclusive ? ' (incl.)' : '' }}</span><span>{{ Money::format($sale->tax_total) }}</span></div>@endif
        @if((float) $sale->round_off)<div class="row"><span>Round-off</span><span>{{ (float) $sale->round_off > 0 ? '+' : '-' }} {{ Money::format(abs((float) $sale->round_off)) }}</span></div>@endif
    @endif
    <div class="row total"><span>TOTAL</span><span>{{ Money::symbol() }} {{ Money::format($sale->grand_total) }}</span></div>

    <div class="sep"></div>
    @foreach($sale->allocations as $a)
        <div class="row"><span>{{ $a->payment->method->name }}{{ $a->payment->reference_no ? ' ('.$a->payment->reference_no.')' : '' }}</span><span>{{ Money::format($a->amount) }}</span></div>
    @endforeach
    @if((float) $sale->change_amount)
        <div class="row"><span>Cash received</span><span>{{ Money::format($sale->amount_tendered) }}</span></div>
        <div class="row b"><span>Change</span><span>{{ Money::format($sale->change_amount) }}</span></div>
    @endif
    @if(! $voided && (float) $sale->due_amount)<div class="row b"><span>BALANCE DUE</span><span>{{ Money::format($sale->due_amount) }}</span></div>@endif

    @if($sale->print_notes && $sale->notes)<div class="sep"></div><div class="small">Note: {{ $sale->notes }}</div>@endif

    @if($s->string('receipt.footer'))<div class="sep"></div><div class="c small">{{ $s->string('receipt.footer') }}</div>@endif
    @if($barcode)<div class="bc">{!! $barcode !!}<div class="small">{{ $sale->invoice_no }}</div></div>@endif
    @if($s->bool('printing.open_cash_drawer'))<div class="small c">&nbsp;</div>@endif
</div>
@endfor
@if($autoprint)
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
@endif
</body>
</html>
