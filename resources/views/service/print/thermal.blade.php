@php
    use App\Models\ServiceJob;
    use App\Support\Decimal;
    use App\Support\Money;

    $width = (int) $s->get('printing.paper_width_mm');
    $mt = $s->get('printing.margin_top_mm'); $mr = $s->get('printing.margin_right_mm'); $mb = $s->get('printing.margin_bottom_mm'); $ml = $s->get('printing.margin_left_mm');
    $font = $s->get('printing.font_size_pt');
    $shop = $s->string('shop.name') ?: config('app.name');
    $slip = $doc === 'slip';
    $cancelled = $job->isCancelled();
    $taxLabel = $s->string('tax.label');
    $contact = $job->contact_name ?: $job->customer->name;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $number }}</title>
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
    <div class="bar"><button type="button" onclick="window.print()">Print</button> {{ $number }} · {{ $slip ? 'job slip' : 'service invoice' }} · {{ $width }} mm{{ $duplicate ? ' · marked DUPLICATE' : '' }}</div>
@endunless
@for($copy = 1; $copy <= $copies; $copy++)
<div class="copy">
    <div class="c">
        @if($logo)<img src="{{ $logo }}" alt="" class="logo">@endif
        <div class="shop">{{ $shop }}</div>
        @if($s->string('shop.address'))<div>{{ $s->string('shop.address') }}</div>@endif
        @if($s->string('shop.phone'))<div>Tel: {{ $s->string('shop.phone') }}</div>@endif
        @if($s->string('shop.ntn') && ! $slip)<div>NTN: {{ $s->string('shop.ntn') }}</div>@endif
    </div>

    @if($duplicate)<div class="banner">DUPLICATE</div>@endif
    @if($cancelled)<div class="banner">*** CANCELLED ***</div>@endif

    <div class="sep"></div>
    <div class="row"><span class="b">{{ $slip ? 'SERVICE JOB SLIP' : ((float) $job->tax_amount ? $taxLabel.' SERVICE INVOICE' : 'SERVICE INVOICE') }}</span><span class="b">{{ $number }}</span></div>
    @if(! $slip)<div class="row"><span>Job {{ $job->job_no }}</span><span>{{ $job->invoiced_at->format('d/m/Y H:i') }}</span></div>
    @else<div class="row"><span>Received</span><span>{{ $job->received_at->format('d/m/Y H:i') }}</span></div>@endif
    <div>{{ $contact }}{{ $job->contact_phone ? ' · '.$job->contact_phone : '' }}</div>
    <div class="sep"></div>

    <div class="b">{{ $job->serviceType->name }}</div>
    <div>{{ collect([ServiceJob::DEVICE_TYPES[$job->device_type] ?? null, $job->brand?->name, $job->model])->filter()->implode(' · ') }}</div>
    @if($job->serial_number)<div class="small">S/N: {{ $job->serial_number }}</div>@endif
    @if($job->cartridge_model)<div class="small">Cartridge: {{ $job->cartridge_model }}</div>@endif

    @if($slip)
        <div class="sep"></div>
        <div class="small b">Problem reported</div>
        <div>{{ $job->reported_problem }}</div>
        @if($job->accessories_received)<div class="small">Accessories: {{ $job->accessories_received }}</div>@endif
        @if($job->expected_delivery_at)<div class="row"><span>Promised for</span><span class="b">{{ $job->expected_delivery_at->format('d/m/Y') }}</span></div>@endif
        @if($job->estimate_amount !== null)<div class="row"><span>Estimate</span><span>{{ Money::format($job->estimate_amount) }}</span></div>@endif
        @if((float) $job->paid_amount > 0 && ! $cancelled)<div class="row b"><span>Advance paid</span><span>{{ Money::format($job->paid_amount) }}</span></div>@endif
        @if($job->customer_notes)<div class="small">Note: {{ $job->customer_notes }}</div>@endif
        @if($s->string('receipt.service_terms'))<div class="sep"></div><div class="small">{{ $s->string('receipt.service_terms') }}</div>@endif
    @else
        <div class="sep"></div>
        @foreach($items as $i)
            <div class="item">
                <div>{{ $i->description }}</div>
                <div class="row small"><span>{{ Decimal::trim((string) $i->quantity) }} x {{ Money::format($i->unit_price) }}</span><span class="b">{{ Money::format($i->line_total) }}</span></div>
                @if((float) $i->discount_amount)<div class="small r">discount - {{ Money::format($i->discount_amount) }}</div>@endif
            </div>
        @endforeach
        <div class="sep"></div>
        @if((float) $job->discount_amount || (float) $job->tax_amount)
            <div class="row"><span>Subtotal</span><span>{{ Money::format(bcadd((string) $job->service_charges, (string) $job->parts_amount, 2)) }}</span></div>
            @if((float) $job->discount_amount)<div class="row"><span>Discount</span><span>- {{ Money::format($job->discount_amount) }}</span></div>@endif
            @if((float) $job->tax_amount)<div class="row"><span>{{ $taxLabel }}{{ $job->tax_inclusive ? ' (incl.)' : '' }}</span><span>{{ Money::format($job->tax_amount) }}</span></div>@endif
        @endif
        <div class="row total"><span>TOTAL</span><span>{{ Money::symbol() }} {{ Money::format($job->grand_total) }}</span></div>
        <div class="sep"></div>
        @foreach($job->allocations as $a)
            @if($a->payment->status === 'posted')<div class="row"><span>{{ $a->payment->method->name }}{{ $a->payment->reference_no ? ' ('.$a->payment->reference_no.')' : '' }}</span><span>{{ Money::format($a->amount) }}</span></div>@endif
        @endforeach
        @if((float) $job->due_amount > 0)<div class="row b"><span>BALANCE DUE</span><span>{{ Money::format($job->due_amount) }}</span></div>@endif
        @if($s->string('receipt.footer'))<div class="sep"></div><div class="c small">{{ $s->string('receipt.footer') }}</div>@endif
    @endif

    @if($barcode)<div class="bc">{!! $barcode !!}<div class="small">{{ $number }}</div></div>@endif
</div>
@endfor
@if($autoprint)
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
@endif
</body>
</html>
