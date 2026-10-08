@php
    use App\Models\CustomerLedgerEntry;
    use App\Support\Money;
    $shop = $s->string('shop.name') ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement · {{ $customer->name }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4; margin: 12mm; }
        body { margin: 0; font-family: {!! $pdf ? "'DejaVu Sans', sans-serif" : "'Segoe UI', Arial, sans-serif" !!}; font-size: {{ $pdf ? '8.5' : '10' }}pt; color: #111; background: {{ $pdf ? '#fff' : '#e5e7eb' }}; }
        .sheet { @if($pdf) position: relative; @else width: 186mm; min-height: 270mm; margin: 10px auto; padding: 8mm; background: #fff; @endif }
        h1 { margin: 0; font-size: 16pt; } h2 { margin: 0 0 4px; font-size: 12pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 8pt; text-transform: uppercase; letter-spacing: .03em; }
        .r { text-align: right; } .muted { color: #555; } .head td { border: 0; padding: 0; }
        tfoot td { border-top: 2px solid #111; font-weight: 700; }
        .logo { max-height: 16mm; max-width: 45mm; }
        .bar { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; padding: 8px 12px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1; }
        .bar button { padding: 4px 12px; margin-right: 8px; }
        @media print { body { background: #fff; } .sheet { margin: 0; padding: 0; width: auto; min-height: 0; } .bar { display: none; } }
    </style>
</head>
<body>
@unless($pdf)<div class="bar"><button type="button" onclick="window.print()">Print</button> Statement of account · {{ $customer->name }}</div>@endunless
<div class="sheet">
    <table class="head">
        <tr>
            <td style="width:60%">
                @if($logo)<img src="{{ $logo }}" alt="" class="logo"><br>@endif
                <h1>{{ $shop }}</h1>
                <span class="muted">{{ $s->string('shop.address') }}</span><br>
                <span class="muted">{{ collect([$s->string('shop.phone') ? 'Tel: '.$s->string('shop.phone') : null, $s->string('shop.email')])->filter()->implode('  ·  ') }}</span>
            </td>
            <td class="r"><h2>STATEMENT OF ACCOUNT</h2>
                <div>{{ $from ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : 'Beginning' }} – {{ $to ? \Illuminate\Support\Carbon::parse($to)->format('d M Y') : 'today' }}</div>
                <div class="muted">Printed {{ now()->format('d M Y, H:i') }}</div></td>
        </tr>
    </table>
    <hr style="border:0;border-top:2px solid #111;margin:8px 0">
    <p><strong>{{ $customer->name }}</strong>@if($customer->company) · {{ $customer->company }}@endif <span class="muted">({{ $customer->code }})</span><br>{{ $customer->phone }} {{ $customer->address }}</p>

    <table>
        <thead><tr><th>Date</th><th>What</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr></thead>
        <tbody>
        @if($from)<tr><td colspan="4" class="muted">Brought forward</td><td class="r">{{ Money::format($statement['opening']) }}</td></tr>@endif
        @forelse($statement['rows'] as $row)
            @php($e = $row['entry'])
            <tr>
                <td>{{ $e->entry_date->format('d M Y') }}</td>
                <td>{{ CustomerLedgerEntry::label($e->entry_type) }}<div class="muted">{{ $e->description }}</div></td>
                <td class="r">{{ (float) $e->debit ? Money::format($e->debit) : '' }}</td>
                <td class="r">{{ (float) $e->credit ? Money::format($e->credit) : '' }}</td>
                <td class="r">{{ Money::format($row['balance']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No entries in this period.</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><td colspan="2">Totals · closing balance</td><td class="r">{{ Money::format($statement['debit']) }}</td><td class="r">{{ Money::format($statement['credit']) }}</td><td class="r">{{ Money::format($statement['closing']) }}</td></tr></tfoot>
    </table>

    @if($open->isNotEmpty())
        <h2 style="margin-top:14px">Invoices still unpaid</h2>
        <table>
            <thead><tr><th>Invoice</th><th>Date</th><th class="r">Total</th><th class="r">Due</th></tr></thead>
            <tbody>
            @foreach($open as $inv)
                <tr><td>{{ $inv->invoice_no }}</td><td>{{ $inv->sale_date->format('d M Y') }}</td><td class="r">{{ Money::format($inv->grand_total) }}</td><td class="r">{{ Money::format($inv->due_amount) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p class="muted" style="margin-top:10px">{{ Money::symbol() }} · A positive closing balance is the amount owed to us; a negative one is the customer's money we hold.</p>
</div>
</body>
</html>
