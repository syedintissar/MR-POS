@php use App\Services\Reports\ReportResult; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $result->title }} · {{ $shop }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4 landscape; margin: 12mm; }
        body { margin: 0; font-family: {{ $pdf ? "'DejaVu Sans', sans-serif" : "'Segoe UI', Arial, sans-serif" }}; font-size: {{ $wide ? '9px' : '10.5px' }}; color: #111; {{ $pdf ? '' : 'background: #e5e7eb;' }} }
        .bar { position: sticky; top: 0; display: flex; gap: .75rem; align-items: center; padding: .6rem 1rem; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .bar button { padding: .4rem .9rem; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; font-size: 14px; cursor: pointer; }
        .sheet { {{ $pdf ? '' : 'width: 297mm; min-height: 210mm; margin: 12px auto; padding: 12mm; background: #fff;' }} }
        h1 { margin: 0; font-size: 18px; } h2 { margin: 14px 0 4px; font-size: 13px; }
        .head { border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
        .head .sub { color: #444; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { padding: 3px 5px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: .85em; text-transform: uppercase; letter-spacing: .03em; }
        .r { text-align: right; white-space: nowrap; }
        tfoot td { border-top: 2px solid #111; font-weight: 700; }
        .note { color: #555; margin-top: 8px; }
        .foot { margin-top: 10px; color: #666; font-size: .9em; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; padding: 0; width: auto; } }
    </style>
</head>
<body>
@unless($pdf)<div class="bar"><button type="button" onclick="window.print()">Print</button><span>A4 landscape · {{ $result->title }}</span></div>@endunless
<div class="sheet">
    <div class="head">
        <h1>{{ $shop }} · {{ $result->title }}</h1>
        <div class="sub">{{ $result->subtitle }}</div>
    </div>

    @foreach($result->tables as $t)
        @if($t['title'])<h2>{{ $t['title'] }}</h2>@endif
        <table>
            <thead><tr>@foreach($t['columns'] as $c)<th class="{{ ReportResult::numeric($c['type']) ? 'r' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($t['rows'] as $row)
                <tr>@foreach($t['columns'] as $c)<td class="{{ ReportResult::numeric($c['type']) ? 'r' : '' }}">{{ ReportResult::display($c['type'], $row[$c['key']] ?? null) }}</td>@endforeach</tr>
            @endforeach
            @if($t['rows'] === [])<tr><td colspan="{{ count($t['columns']) }}">Nothing to show for these dates.</td></tr>@endif
            </tbody>
            @if($t['totals'] !== null && $t['rows'] !== [])
                <tfoot><tr>@foreach($t['columns'] as $c)<td class="{{ ReportResult::numeric($c['type']) ? 'r' : '' }}">{{ ReportResult::display($c['type'], $t['totals'][$c['key']] ?? null) }}</td>@endforeach</tr></tfoot>
            @endif
        </table>
    @endforeach

    @foreach($result->notes as $n)<div class="note">{{ $n }}</div>@endforeach
    <div class="foot">Printed {{ $printedAt->format('d M Y, H:i') }} by {{ $printedBy }}</div>
</div>
</body>
</html>
