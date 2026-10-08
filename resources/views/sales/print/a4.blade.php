@php
    use App\Support\Decimal;
    use App\Support\Money;

    $shop = $s->string('shop.name') ?: config('app.name');
    $voided = $sale->isVoided();
    $taxLabel = $s->string('tax.label');
    $hasTax = (float) $sale->tax_total > 0;
    $hasDiscount = (float) $sale->line_discount_total > 0 || (float) $sale->invoice_discount_amount > 0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->invoice_no }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4; margin: 12mm; }
        body { margin: 0; font-family: {!! $pdf ? "'DejaVu Sans', sans-serif" : "'Segoe UI', DejaVu Sans, Arial, sans-serif" !!}; font-size: {{ $pdf ? '9' : '10' }}pt; color: #111; background: {{ $pdf ? '#fff' : '#e5e7eb' }}; }
        .sheet { @if($pdf) position: relative; @else width: 186mm; min-height: 270mm; margin: 10px auto; padding: 8mm; background: #fff; position: relative; @endif }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; vertical-align: top; text-align: left; }
        th { background: #f3f4f6; font-size: 8.5pt; text-transform: uppercase; border-bottom: 1px solid #999; }
        td { border-bottom: 1px solid #e5e7eb; }
        .r { text-align: right; } .c { text-align: center; } .b { font-weight: 700; } .muted { color: #555; } .small { font-size: 8.5pt; }
        .head td { border: 0; padding: 0; }
        .title { font-size: 20pt; font-weight: 700; letter-spacing: 1px; }
        .shop { font-size: 15pt; font-weight: 700; }
        .totals { width: 80mm; margin-left: auto; margin-top: 8px; }
        .totals td { border: 0; padding: 2px 6px; }
        .totals .grand td { border-top: 2px solid #111; font-size: 13pt; font-weight: 700; padding-top: 5px; }
        .watermark { position: absolute; left: 0; right: 0; top: 95mm; text-align: center; font-size: 70pt; font-weight: 700; color: rgba(150, 0, 0, .13); transform: rotate(-24deg); pointer-events: none; letter-spacing: 6px; }
        .sign td { border: 0; padding-top: 22mm; text-align: center; } .sign span { border-top: 1px solid #111; padding: 3px 14px; display: inline-block; min-width: 48mm; }
        .logo { max-height: 18mm; max-width: 50mm; }
        .bar { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; padding: 8px 12px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1; }
        .bar button { padding: 4px 12px; margin-right: 8px; }
        @media print { body { background: #fff; } .sheet { margin: 0; padding: 0; width: auto; min-height: 0; } .bar { display: none; } }
    </style>
</head>
<body>
@unless($autoprint || $pdf)
    <div class="bar"><button type="button" onclick="window.print()">Print</button> {{ $sale->invoice_no }} · A4 invoice{{ $voided ? ' · marked VOID' : ($duplicate ? ' · marked DUPLICATE' : '') }}</div>
@endunless
<div class="sheet">
    @if($voided)<div class="watermark">VOID</div>@elseif($duplicate)<div class="watermark">DUPLICATE</div>@endif

    <table class="head">
        <tr>
            <td style="width:60%">
                @if($logo)<img src="{{ $logo }}" alt="" class="logo"><br>@endif
                <span class="shop">{{ $shop }}</span><br>
                <span class="small muted">{{ $s->string('shop.address') }}</span><br>
                <span class="small muted">{{ collect([$s->string('shop.phone') ? 'Tel: '.$s->string('shop.phone') : null, $s->string('shop.email'), $s->string('shop.ntn') ? 'NTN: '.$s->string('shop.ntn') : null])->filter()->implode('  ·  ') }}</span>
                @if($s->string('receipt.header'))<br><span class="small">{{ $s->string('receipt.header') }}</span>@endif
            </td>
            <td class="r">
                <span class="title">{{ $hasTax ? $taxLabel.' INVOICE' : 'INVOICE' }}</span><br>
                <span class="b" style="font-size:13pt">{{ $sale->invoice_no }}</span><br>
                <span class="small">{{ $sale->sale_date->format('d M Y, H:i') }}</span>
                @if($voided)<br><span class="b" style="color:#9b1c1c">CANCELLED</span>@endif
            </td>
        </tr>
    </table>
    <hr style="border:0;border-top:2px solid #111;margin:8px 0">

    <table class="head" style="margin-bottom:8px">
        <tr>
            <td style="width:60%"><span class="muted small">Billed to</span><br><span class="b">{{ $sale->customer->is_walk_in ? 'Walk-in customer' : $sale->customer->name }}</span>
                @unless($sale->customer->is_walk_in)<br><span class="small">{{ $sale->customer->phone }}</span><br><span class="small">{{ $sale->customer->address }}</span>@endunless</td>
            <td class="r small">
                @if($s->bool('receipt.show_cashier') && $sale->cashier)Served by: {{ $sale->cashier->name }}<br>@endif
                Payment: {{ $sale->allocations->map(fn ($a) => $a->payment->method->name)->unique()->implode(', ') ?: '-' }}
            </td>
        </tr>
    </table>

    <table>
        <thead>
        <tr><th style="width:6%">#</th><th>Description</th><th class="r" style="width:9%">Qty</th><th class="r" style="width:13%">Rate</th>@if($hasDiscount)<th class="r" style="width:12%">Discount</th>@endif @if($hasTax)<th class="r" style="width:11%">{{ $taxLabel }}</th>@endif<th class="r" style="width:14%">Amount</th></tr>
        </thead>
        <tbody>
        @foreach($sale->items as $i => $item)
            @php($disc = bcadd((string) $item->discount_amount, (string) $item->invoice_discount_share, 2))
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->product_name }}<br><span class="small muted">{{ $item->sku }}@if($item->serial_number) · S/N {{ $item->serial_number }}@endif @if($item->warranty_until) · warranty until {{ $item->warranty_until->format('d M Y') }}@endif</span></td>
                <td class="r">{{ Decimal::trim((string) $item->quantity) }}</td>
                <td class="r">{{ Money::format($item->unit_price) }}</td>
                @if($hasDiscount)<td class="r">{{ (float) $disc ? Money::format($disc) : '' }}</td>@endif
                @if($hasTax)<td class="r">{{ (float) $item->tax_amount ? Money::format($item->tax_amount) : '' }}</td>@endif
                <td class="r">{{ Money::format($item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="r">{{ Money::format($sale->items_total) }}</td></tr>
        @if((float) $sale->line_discount_total)<tr><td>Item discounts</td><td class="r">- {{ Money::format($sale->line_discount_total) }}</td></tr>@endif
        @if((float) $sale->invoice_discount_amount)<tr><td>Discount{{ $sale->invoice_discount_percent ? ' ('.Decimal::trim((string) $sale->invoice_discount_percent).'%)' : '' }}</td><td class="r">- {{ Money::format($sale->invoice_discount_amount) }}</td></tr>@endif
        @if($hasTax)<tr><td>{{ $taxLabel }}{{ $sale->tax_inclusive ? ' (included in prices)' : '' }}</td><td class="r">{{ Money::format($sale->tax_total) }}</td></tr>@endif
        @if((float) $sale->round_off)<tr><td>Round-off</td><td class="r">{{ (float) $sale->round_off > 0 ? '+' : '-' }} {{ Money::format(abs((float) $sale->round_off)) }}</td></tr>@endif
        <tr class="grand"><td>Total</td><td class="r">{{ Money::symbol() }} {{ Money::format($sale->grand_total) }}</td></tr>
        @foreach($sale->allocations as $a)
            <tr><td class="small muted">Paid by {{ $a->payment->method->name }}{{ $a->payment->reference_no ? ' ('.$a->payment->reference_no.')' : '' }}</td><td class="r small">{{ Money::format($a->amount) }}</td></tr>
        @endforeach
        @if((float) $sale->change_amount)<tr><td class="small muted">Cash received {{ Money::format($sale->amount_tendered) }}, change</td><td class="r small">{{ Money::format($sale->change_amount) }}</td></tr>@endif
        @if(! $voided && (float) $sale->due_amount)<tr><td class="b">Balance due</td><td class="r b">{{ Money::format($sale->due_amount) }}</td></tr>@endif
    </table>

    @if($sale->print_notes && $sale->notes)<p class="small"><span class="b">Note:</span> {{ $sale->notes }}</p>@endif
    @if($s->string('receipt.footer'))<p class="c muted" style="margin-top:14mm">{{ $s->string('receipt.footer') }}</p>@endif

    <table class="sign"><tr><td><span>Customer signature</span></td><td><span>Authorised signature</span></td></tr></table>
    @if($barcode)<div class="c" style="margin-top:6mm">@if($barcodeImage)<img src="{{ $barcodeImage }}" style="width:55mm;height:11mm" alt="">@else<div style="display:inline-block;width:55mm">{!! $barcode !!}</div>@endif<div class="small muted">{{ $sale->invoice_no }}</div></div>@endif
</div>
@if($autoprint)
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
@endif
</body>
</html>
