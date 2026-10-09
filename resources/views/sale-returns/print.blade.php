@php
    use App\Support\Decimal;
    use App\Support\Money;

    $width = (int) $s->get('printing.paper_width_mm');
    $mt = $s->get('printing.margin_top_mm'); $mr = $s->get('printing.margin_right_mm'); $mb = $s->get('printing.margin_bottom_mm'); $ml = $s->get('printing.margin_left_mm');
    $font = $s->get('printing.font_size_pt');
    $shop = $s->string('shop.name') ?: config('app.name');
    $voided = $return->isVoided();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $return->return_no }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { size: {{ $width }}mm auto; margin: {{ $mt }}mm {{ $mr }}mm {{ $mb }}mm {{ $ml }}mm; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { width: {{ $width - $ml - $mr }}mm; font-family: Consolas, 'Lucida Console', 'Courier New', monospace; font-size: {{ $font }}pt; line-height: 1.25; }
        .copy { page-break-after: always; break-after: page; padding-bottom: 2mm; }
        .copy:last-child { page-break-after: auto; break-after: auto; }
        .c { text-align: center; } .b { font-weight: 700; }
        .shop { font-size: {{ $font + 3 }}pt; font-weight: 700; }
        .logo { max-height: 14mm; max-width: 60%; margin: 0 auto 1mm; display: block; }
        .sep { border-top: 1px dashed #000; margin: 1.5mm 0; }
        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .row > span:first-child { flex: 1; min-width: 0; overflow-wrap: anywhere; } .row > span:last-child { white-space: nowrap; }
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
    <div class="bar"><button type="button" onclick="window.print()">Print</button> {{ $return->return_no }} · return receipt{{ $voided ? ' · marked VOID' : ($duplicate ? ' · marked DUPLICATE' : '') }}</div>
@endunless
@for($copy = 1; $copy <= $copies; $copy++)
<div class="copy">
    <div class="c">
        @if($logo)<img src="{{ $logo }}" alt="" class="logo">@endif
        <div class="shop">{{ $shop }}</div>
        @if($s->string('shop.address'))<div>{{ $s->string('shop.address') }}</div>@endif
        @if($s->string('shop.phone'))<div>Tel: {{ $s->string('shop.phone') }}</div>@endif
    </div>

    @if($voided)<div class="banner">*** VOID ***</div>@elseif($duplicate)<div class="banner">DUPLICATE</div>@endif

    <div class="sep"></div>
    <div class="row"><span class="b">RETURN RECEIPT</span><span class="b">{{ $return->return_no }}</span></div>
    <div class="row"><span>{{ $return->return_date->format('d/m/Y') }}</span><span>{{ $return->return_date->format('H:i') }}</span></div>
    <div>Invoice: {{ $return->sale->invoice_no }}</div>
    @if($s->bool('receipt.show_cashier') && $return->creator)<div>Served by: {{ $return->creator->name }}</div>@endif
    <div>Customer: {{ $return->customer->is_walk_in ? 'Walk-in customer' : $return->customer->name }}</div>
    <div class="sep"></div>

    @foreach($return->items as $row)
        <div>{{ $row->saleItem?->product_name ?? $row->product->name }}</div>
        <div class="row small"><span>{{ Decimal::trim((string) $row->quantity) }} x {{ Money::format(Decimal::div((string) $row->line_total, (string) $row->quantity, 2)) }}</span><span>{{ Money::format($row->line_total) }}</span></div>
    @endforeach

    <div class="sep"></div>
    <div class="row total"><span>RETURNED</span><span>{{ Money::symbol() }} {{ Money::format($return->total) }}</span></div>
    @if((float) $return->applied_to_due)<div class="row"><span>Taken off amount owed</span><span>{{ Money::format($return->applied_to_due) }}</span></div>@endif
    @if((float) $return->refund_amount)<div class="row b"><span>Money back{{ $return->refundPayment?->method ? ' ('.$return->refundPayment->method->name.')' : '' }}</span><span>{{ Money::format($return->refund_amount) }}</span></div>@endif
    @if((float) $return->store_credit_amount)<div class="row b"><span>Store credit</span><span>{{ Money::format($return->store_credit_amount) }}</span></div>@endif
    <div class="small">Reason: {{ $return->reason }}</div>

    @if($s->string('receipt.footer'))<div class="sep"></div><div class="c small">{{ $s->string('receipt.footer') }}</div>@endif
    @if($barcode)<div class="bc">{!! $barcode !!}<div class="small">{{ $return->return_no }}</div></div>@endif
</div>
@endfor
@if($autoprint)
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
@endif
</body>
</html>
