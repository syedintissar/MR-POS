@php
    use App\Models\ServiceJob;
    use App\Support\Decimal;
    use App\Support\Money;

    $shop = $s->string('shop.name') ?: config('app.name');
    $slip = $doc === 'slip';
    $cancelled = $job->isCancelled();
    $taxLabel = $s->string('tax.label');
    $hasTax = (float) $job->tax_amount > 0;
    $contact = $job->contact_name ?: $job->customer->name;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $number }}</title>
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
        .title { font-size: 18pt; font-weight: 700; letter-spacing: 1px; }
        .shop { font-size: 15pt; font-weight: 700; }
        .box td { border: 0; padding: 2px 6px; }
        .totals { width: 80mm; margin-left: auto; margin-top: 8px; }
        .totals td { border: 0; padding: 2px 6px; }
        .totals .grand td { border-top: 2px solid #111; font-size: 13pt; font-weight: 700; padding-top: 5px; }
        .watermark { position: absolute; left: 0; right: 0; top: 95mm; text-align: center; font-size: 70pt; font-weight: 700; color: rgba(150, 0, 0, .13); transform: rotate(-24deg); letter-spacing: 6px; }
        .sign td { border: 0; padding-top: 22mm; text-align: center; } .sign span { border-top: 1px solid #111; padding: 3px 14px; display: inline-block; min-width: 48mm; }
        .logo { max-height: 18mm; max-width: 50mm; }
        .bar { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; padding: 8px 12px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1; }
        .bar button { padding: 4px 12px; margin-right: 8px; }
        @media print { body { background: #fff; } .sheet { margin: 0; padding: 0; width: auto; min-height: 0; } .bar { display: none; } }
    </style>
</head>
<body>
@unless($autoprint || $pdf)
    <div class="bar"><button type="button" onclick="window.print()">Print</button> {{ $number }} · A4 {{ $slip ? 'job slip' : 'service invoice' }}{{ $duplicate ? ' · marked DUPLICATE' : '' }}</div>
@endunless
<div class="sheet">
    @if($cancelled)<div class="watermark">CANCELLED</div>@elseif($duplicate)<div class="watermark">DUPLICATE</div>@endif

    <table class="head">
        <tr>
            <td style="width:60%">
                @if($logo)<img src="{{ $logo }}" alt="" class="logo"><br>@endif
                <span class="shop">{{ $shop }}</span><br>
                <span class="small muted">{{ $s->string('shop.address') }}</span><br>
                <span class="small muted">{{ collect([$s->string('shop.phone') ? 'Tel: '.$s->string('shop.phone') : null, $s->string('shop.email'), (! $slip && $s->string('shop.ntn')) ? 'NTN: '.$s->string('shop.ntn') : null])->filter()->implode('  ·  ') }}</span>
            </td>
            <td class="r">
                <span class="title">{{ $slip ? 'SERVICE JOB' : ($hasTax ? $taxLabel.' SERVICE INVOICE' : 'SERVICE INVOICE') }}</span><br>
                <span class="b" style="font-size:13pt">{{ $number }}</span><br>
                @if($slip)<span class="small">Received {{ $job->received_at->format('d M Y, H:i') }}</span>
                @else<span class="small">{{ $job->invoiced_at->format('d M Y, H:i') }} · job {{ $job->job_no }}</span>@endif
                @if($barcode)<div style="margin-top:4px">@if($pdf && $barcodeImage)<img src="{{ $barcodeImage }}" alt="" style="height:11mm;width:50mm">@else<span style="display:inline-block;width:50mm;height:11mm">{!! $barcode !!}</span>@endif</div>@endif
            </td>
        </tr>
    </table>

    <table class="box" style="margin-top:10px;border:1px solid #d1d5db">
        <tr><td style="width:50%"><span class="small muted">Customer</span><br><span class="b">{{ $contact }}</span>@if($job->contact_phone)<br>{{ $job->contact_phone }}@endif</td>
            <td><span class="small muted">Service</span><br><span class="b">{{ $job->serviceType->name }}</span>@if($job->technician && $slip)<br><span class="small">Technician: {{ $job->technician->name }}</span>@endif</td></tr>
        <tr><td><span class="small muted">Device</span><br>{{ collect([ServiceJob::DEVICE_TYPES[$job->device_type] ?? null, $job->brand?->name, $job->model])->filter()->implode(' · ') ?: '—' }}</td>
            <td><span class="small muted">Serial number / cartridge</span><br>{{ $job->serial_number ?: '—' }}{{ $job->cartridge_model ? ' · '.$job->cartridge_model : '' }}</td></tr>
    </table>

    @if($slip)
        <table class="box" style="margin-top:8px">
            <tr><td><span class="small muted">Problem reported</span><br>{{ $job->reported_problem }}</td></tr>
            @if($job->accessories_received)<tr><td><span class="small muted">Accessories received</span><br>{{ $job->accessories_received }}</td></tr>@endif
            <tr><td>
                @if($job->expected_delivery_at)Promised for <span class="b">{{ $job->expected_delivery_at->format('d M Y') }}</span>  @endif
                @if($job->estimate_amount !== null) · Estimate <span class="b">{{ Money::symbol() }} {{ Money::format($job->estimate_amount) }}</span>@endif
                @if((float) $job->paid_amount > 0 && ! $cancelled) · Advance paid <span class="b">{{ Money::symbol() }} {{ Money::format($job->paid_amount) }}</span>@endif
            </td></tr>
            @if($job->customer_notes)<tr><td><span class="small muted">Note</span><br>{{ $job->customer_notes }}</td></tr>@endif
        </table>
        @if($s->string('receipt.service_terms'))<p class="small muted" style="margin-top:12px">{{ $s->string('receipt.service_terms') }}</p>@endif
        <table class="sign"><tr><td><span>Customer signature</span></td><td><span>For {{ $shop }}</span></td></tr></table>
    @else
        <table style="margin-top:10px">
            <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Discount</th><th class="r">Amount</th></tr></thead>
            <tbody>
            @foreach($items as $i)
                <tr><td>{{ $i->description }}</td><td class="r">{{ Decimal::trim((string) $i->quantity) }}</td><td class="r">{{ Money::format($i->unit_price) }}</td><td class="r">{{ (float) $i->discount_amount ? Money::format($i->discount_amount) : '' }}</td><td class="r">{{ Money::format($i->line_total) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <table class="totals">
            @if((float) $job->discount_amount || $hasTax)
                <tr><td>Subtotal</td><td class="r">{{ Money::format(bcadd((string) $job->service_charges, (string) $job->parts_amount, 2)) }}</td></tr>
                @if((float) $job->discount_amount)<tr><td>Discount</td><td class="r">- {{ Money::format($job->discount_amount) }}</td></tr>@endif
                @if($hasTax)<tr><td>{{ $taxLabel }}{{ $job->tax_inclusive ? ' (included)' : '' }}</td><td class="r">{{ Money::format($job->tax_amount) }}</td></tr>@endif
            @endif
            <tr class="grand"><td>TOTAL</td><td class="r">{{ Money::symbol() }} {{ Money::format($job->grand_total) }}</td></tr>
            @foreach($job->allocations as $a)
                @if($a->payment->status === 'posted')<tr><td class="small">{{ $a->payment->method->name }}{{ $a->payment->reference_no ? ' ('.$a->payment->reference_no.')' : '' }}</td><td class="r small">{{ Money::format($a->amount) }}</td></tr>@endif
            @endforeach
            @if((float) $job->due_amount > 0)<tr><td class="b">Balance due</td><td class="r b">{{ Money::format($job->due_amount) }}</td></tr>@endif
        </table>
        @if($s->string('receipt.footer'))<p class="c small muted" style="margin-top:14px">{{ $s->string('receipt.footer') }}</p>@endif
    @endif
</div>
</body>
</html>
