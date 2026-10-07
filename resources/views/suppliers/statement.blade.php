@php
    use App\Models\SupplierLedgerEntry;
    use App\Support\Money;
    $settings = app(\App\Services\SettingsService::class);
    $shop = $settings->string('shop.name') ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement · {{ $supplier->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #111; background: #e5e7eb; }
        .bar { position: sticky; top: 0; display: flex; gap: .75rem; align-items: center; padding: .6rem 1rem; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .bar button { padding: .4rem .9rem; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; font-size: 14px; cursor: pointer; }
        .sheet { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 14mm; background: #fff; }
        h1 { margin: 0; font-size: 20px; } h2 { margin: 0 0 4px; font-size: 14px; }
        .head { display: flex; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        .r { text-align: right; } tfoot td { border-top: 2px solid #111; font-weight: 700; }
        .muted { color: #555; }
        @page { size: A4; margin: 0; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Print</button><span>Statement of account · {{ $supplier->name }}</span></div>
    <div class="sheet">
        <div class="head">
            <div><h1>{{ $shop }}</h1><div>{{ $settings->string('shop.address') }}</div><div>{{ $settings->string('shop.phone') }}</div></div>
            <div style="text-align:right"><h2>SUPPLIER ACCOUNT STATEMENT</h2>
                <div>{{ $from ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : 'Beginning' }} – {{ $to ? \Illuminate\Support\Carbon::parse($to)->format('d M Y') : 'today' }}</div>
                <div class="muted">Printed {{ now()->format('d M Y, H:i') }}</div></div>
        </div>
        <p><strong>{{ $supplier->name }}</strong>@if($supplier->company) · {{ $supplier->company }}@endif <span class="muted">({{ $supplier->code }})</span><br>{{ $supplier->phone }} {{ $supplier->address }}</p>

        <table>
            <thead><tr><th>Date</th><th>What</th><th class="r">Paid / returned</th><th class="r">Bought</th><th class="r">Balance</th></tr></thead>
            <tbody>
            @if($from)<tr><td colspan="4" class="muted">Brought forward</td><td class="r">{{ Money::format($statement['opening']) }}</td></tr>@endif
            @forelse($statement['rows'] as $row)
                @php($e = $row['entry'])
                <tr>
                    <td>{{ $e->entry_date->format('d M Y') }}</td>
                    <td>{{ SupplierLedgerEntry::label($e->entry_type) }}<div class="muted">{{ $e->description }}</div></td>
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
        <p class="muted" style="margin-top:10px">{{ Money::symbol() }} · A positive closing balance is the amount owed to the supplier; a negative one is an advance held by the supplier.</p>
    </div>
</body>
</html>
