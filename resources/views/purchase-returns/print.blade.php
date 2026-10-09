@php
    use App\Support\Decimal;
    use App\Support\Money;
    $settings = app(\App\Services\SettingsService::class);
    $shop = $settings->string('shop.name') ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Return note {{ $return->return_no }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #111; background: #e5e7eb; }
        .bar { position: sticky; top: 0; display: flex; gap: .75rem; align-items: center; padding: .6rem 1rem; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .bar button { padding: .4rem .9rem; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; font-size: 14px; cursor: pointer; }
        .sheet { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 14mm; background: #fff; }
        h1 { margin: 0; font-size: 20px; } h2 { margin: 0 0 4px; font-size: 14px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        .r { text-align: right; } .mono { font-family: Consolas, monospace; }
        .totals { width: 80mm; margin: 12px 0 0 auto; }
        .totals td { border: 0; padding: 2px 6px; } .totals .grand td { border-top: 2px solid #111; font-size: 14px; font-weight: 700; padding-top: 6px; }
        .tag { display: inline-block; padding: 1px 8px; border: 1px solid #111; border-radius: 10px; font-size: 11px; }
        .sign { display: flex; justify-content: space-between; margin-top: 40mm; } .sign div { width: 60mm; border-top: 1px solid #111; padding-top: 4px; text-align: center; }
        @page { size: A4; margin: 0; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Print</button><span>A4 · {{ $return->return_no }}</span></div>
    <div class="sheet">
        <div class="head">
            <div><h1>{{ $shop }}</h1><div>{{ $settings->string('shop.address') }}</div><div>{{ $settings->string('shop.phone') }}</div></div>
            <div style="text-align:right">
                <h2>RETURN NOTE (GOODS SENT BACK)</h2>
                <div class="mono" style="font-size:16px;font-weight:700">{{ $return->return_no }}</div>
                @if($return->isVoided())<div><span class="tag">CANCELLED</span></div>@endif
            </div>
        </div>

        <div class="meta">
            <div><h2>Supplier</h2><div><strong>{{ $return->supplier->name }}</strong></div>@if($return->supplier->company)<div>{{ $return->supplier->company }}</div>@endif<div>{{ $return->supplier->phone }}</div><div>{{ $return->supplier->address }}</div></div>
            <div style="text-align:right">
                <div>Date: <strong>{{ $return->return_date->format('d M Y, H:i') }}</strong></div>
                @if($return->purchase)<div>Against purchase: <strong>{{ $return->purchase->purchase_no }}</strong>@if($return->purchase->supplier_invoice_no) · their invoice {{ $return->purchase->supplier_invoice_no }}@endif</div>@endif
                <div>Reason: {{ $return->reason }}</div>
            </div>
        </div>

        <table>
            <thead><tr><th>#</th><th>Product</th><th class="r">Qty</th><th class="r">Credit asked</th></tr></thead>
            <tbody>
            @foreach($return->items as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $row->product->name }}<div class="mono" style="color:#555">{{ $row->product->sku }}</div></td>
                    <td class="r">{{ Decimal::trim((string) $row->quantity) }} {{ $row->product->unit?->short_name }}</td>
                    <td class="r">{{ Money::format($row->line_total) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <table class="totals">
            @if((float) $return->tax_amount)
                <tr><td>Goods</td><td class="r">{{ Money::format($return->subtotal) }}</td></tr>
                <tr><td>Tax</td><td class="r">{{ Money::format($return->tax_amount) }}</td></tr>
            @endif
            <tr class="grand"><td>Credit</td><td class="r">{{ Money::withSymbol($return->total) }}</td></tr>
            @if($return->refundPayment)<tr><td>Paid back by supplier ({{ $return->refundPayment->method->name }})</td><td class="r">{{ Money::format($return->refundPayment->amount) }}</td></tr>@endif
        </table>

        <div class="sign"><div>Sent by ({{ $return->creator?->name }})</div><div>Received for {{ $return->supplier->name }}</div></div>
    </div>
</body>
</html>
