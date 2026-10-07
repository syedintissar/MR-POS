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
    <title>Purchase {{ $purchase->label() }}</title>
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
    <div class="bar"><button type="button" onclick="window.print()">Print</button><span>A4 · {{ $purchase->label() }}</span></div>
    <div class="sheet">
        <div class="head">
            <div><h1>{{ $shop }}</h1><div>{{ $settings->string('shop.address') }}</div><div>{{ $settings->string('shop.phone') }}</div></div>
            <div style="text-align:right">
                <h2>PURCHASE RECORD</h2>
                <div class="mono" style="font-size:16px;font-weight:700">{{ $purchase->label() }}</div>
                <div><span class="tag">{{ $purchase->isDraft() ? 'DRAFT' : ($purchase->isVoided() ? 'CANCELLED' : strtoupper($purchase->payment_status === 'partial' ? 'part paid' : $purchase->payment_status)) }}</span></div>
            </div>
        </div>

        <div class="meta">
            <div><h2>Supplier</h2><div><strong>{{ $purchase->supplier->name }}</strong></div>@if($purchase->supplier->company)<div>{{ $purchase->supplier->company }}</div>@endif<div>{{ $purchase->supplier->phone }}</div><div>{{ $purchase->supplier->address }}</div></div>
            <div style="text-align:right">
                <div>Date: <strong>{{ $purchase->purchase_date->format('d M Y') }}</strong></div>
                @if($purchase->supplier_invoice_no)<div>Supplier invoice: <strong>{{ $purchase->supplier_invoice_no }}</strong></div>@endif
                @if($purchase->received_at)<div>Received: {{ $purchase->received_at->format('d M Y, H:i') }} by {{ $purchase->receiver?->name }}</div>@endif
            </div>
        </div>

        <table>
            <thead><tr><th>#</th><th>Product</th><th class="r">Qty</th><th class="r">Cost each</th><th class="r">Discount</th><th class="r">Tax</th><th class="r">Total</th></tr></thead>
            <tbody>
            @foreach($purchase->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->product->name }}<div class="mono" style="color:#555">{{ $item->product->sku }}</div></td>
                    <td class="r">{{ Decimal::trim((string) $item->quantity) }} {{ $item->product->unit->short_name }}</td>
                    <td class="r">{{ Money::format($item->unit_cost, 2) }}</td>
                    <td class="r">{{ (float) $item->discount_amount ? Money::format($item->discount_amount) : '' }}</td>
                    <td class="r">{{ (float) $item->tax_amount ? Money::format($item->tax_amount) : '' }}</td>
                    <td class="r">{{ Money::format($item->line_total) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td>Products</td><td class="r">{{ Money::format($purchase->subtotal) }}</td></tr>
            @if((float) $purchase->discount_amount)<tr><td>Invoice discount</td><td class="r">− {{ Money::format($purchase->discount_amount) }}</td></tr>@endif
            @if((float) $purchase->tax_amount)<tr><td>Invoice tax</td><td class="r">{{ Money::format($purchase->tax_amount) }}</td></tr>@endif
            @if((float) $purchase->additional_cost)<tr><td>Freight / extra costs</td><td class="r">{{ Money::format($purchase->additional_cost) }}</td></tr>@endif
            <tr class="grand"><td>Total</td><td class="r">{{ Money::withSymbol($purchase->grand_total) }}</td></tr>
            @if($purchase->isReceived())
                <tr><td>Paid</td><td class="r">{{ Money::format($purchase->paid_amount) }}</td></tr>
                <tr><td><strong>Still to pay</strong></td><td class="r"><strong>{{ Money::format($purchase->due_amount) }}</strong></td></tr>
            @endif
        </table>

        @if($purchase->notes)<p><strong>Notes:</strong> {{ $purchase->notes }}</p>@endif
        <div class="sign"><div>Received by</div><div>Authorised by</div></div>
    </div>
</body>
</html>
