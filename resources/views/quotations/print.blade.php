@php
    use App\Support\Decimal;
    use App\Support\Money;
    $shop = $s->string('shop.name') ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation {{ $quotation->quotation_no }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: {!! \App\Support\PrintFont::a4() !!}; {!! \App\Support\PrintFont::weight() !!} font-size: 12px; color: #111; background: #e5e7eb; }
        .bar { position: sticky; top: 0; display: flex; gap: .75rem; align-items: center; padding: .6rem 1rem; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .bar button { padding: .4rem .9rem; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; font-size: 14px; cursor: pointer; }
        .sheet { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 14mm; background: #fff; }
        h1 { margin: 0; font-size: 22px; } h2 { margin: 0 0 4px; font-size: 16px; letter-spacing: .08em; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
        .muted { color: #555; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        .r { text-align: right; }
        .totals { width: 80mm; margin: 12px 0 0 auto; }
        .totals td { border: 0; padding: 2px 6px; } .totals .grand td { border-top: 2px solid #111; font-size: 15px; font-weight: 700; padding-top: 6px; }
        .notes { margin-top: 16px; padding: 8px 10px; border: 1px solid #ddd; white-space: pre-line; }
        .sign { display: flex; justify-content: flex-end; margin-top: 30mm; } .sign div { width: 60mm; border-top: 1px solid #111; padding-top: 4px; text-align: center; }
        @page { size: A4; margin: 0; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Print</button><span>A4 · Quotation {{ $quotation->quotation_no }}</span></div>
    <div class="sheet">
        <div class="head">
            <div>
                <h1>{{ $shop }}</h1>
                @if($s->string('shop.tagline'))<div class="muted">{{ $s->string('shop.tagline') }}</div>@endif
                <div>{{ $s->string('shop.address') }}</div>
                <div>{{ collect([$s->string('shop.phone') ? 'Tel: '.$s->string('shop.phone') : null, $s->string('shop.email'), $s->string('shop.ntn') ? 'NTN: '.$s->string('shop.ntn') : null])->filter()->implode('  ·  ') }}</div>
            </div>
            <div style="text-align:right">
                <h2>QUOTATION</h2>
                <div><strong>{{ $quotation->quotation_no }}</strong></div>
                <div>Date: {{ $quotation->quote_date->format('d M Y') }}</div>
                @if($quotation->valid_until)<div>Valid until: {{ $quotation->valid_until->format('d M Y') }}</div>@endif
            </div>
        </div>

        <div class="meta">
            <div><div class="muted">Quotation for</div><strong>{{ $quotation->customer_name }}</strong>
                @if($quotation->customer?->company)<div>{{ $quotation->customer->company }}</div>@endif
                @if($quotation->customer_phone)<div>{{ $quotation->customer_phone }}</div>@endif
                @if($quotation->customer?->address)<div>{{ $quotation->customer->address }}</div>@endif</div>
            <div style="text-align:right">@if($quotation->status === 'cancelled')<strong>CANCELLED</strong>@endif</div>
        </div>

        <table>
            <thead><tr><th style="width:8mm">#</th><th>Description</th><th class="r">Qty</th><th class="r">Price each</th><th class="r">Total</th></tr></thead>
            <tbody>
            @foreach($quotation->items as $n => $i)
                <tr><td>{{ $n + 1 }}</td><td>{{ $i->description }}</td><td class="r">{{ Decimal::trim((string) $i->quantity) }}</td><td class="r">{{ Money::format($i->unit_price) }}</td><td class="r">{{ Money::format($i->line_total) }}</td></tr>
            @endforeach
            </tbody>
        </table>

        <table class="totals">
            @if((float) $quotation->discount_amount)
                <tr><td>Items</td><td class="r">{{ Money::format($quotation->subtotal) }}</td></tr>
                <tr><td>Discount</td><td class="r">− {{ Money::format($quotation->discount_amount) }}</td></tr>
            @endif
            <tr class="grand"><td>Total</td><td class="r">{{ Money::withSymbol($quotation->grand_total) }}</td></tr>
        </table>

        @if($quotation->notes)<div class="notes"><strong>Terms and notes</strong>
{{ $quotation->notes }}</div>@endif

        <div class="sign"><div>For {{ $shop }}</div></div>
    </div>
</body>
</html>
