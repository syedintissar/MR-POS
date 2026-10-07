<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Labels</title>
    <style>
        :root { --w: {{ $size['w'] }}; --h: {{ $size['h'] }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #000; background: #e5e7eb; }
        .bar { position: sticky; top: 0; z-index: 5; display: flex; gap: .75rem; align-items: center; padding: .6rem 1rem; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .bar button { padding: .4rem .9rem; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; font-size: 14px; cursor: pointer; }
        .warn { margin: .75rem 1rem; padding: .6rem .9rem; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; font-size: 13px; }
        .sheet { display: flex; flex-wrap: wrap; gap: 2mm; padding: 4mm; }
        .label { width: var(--w); height: var(--h); padding: 1.2mm 1.6mm; background: #fff; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; page-break-inside: avoid; break-inside: avoid; }
        .name { font-size: 7.5pt; line-height: 1.1; font-weight: 600; max-height: 2.3em; overflow: hidden; }
        .code svg { width: 100%; height: 10mm; display: block; }
        .foot { display: flex; justify-content: space-between; align-items: baseline; font-size: 7pt; }
        .price { font-size: 10pt; font-weight: 700; }
        @if($size['sheet'])
        @page { size: A4; margin: 10mm 7mm; }
        .sheet { gap: 0; padding: 0; }
        .label { border: 0; margin: 0; }
        @else
        @page { size: var(--w) var(--h); margin: 0; }
        .sheet { display: block; padding: 0; }
        .label { margin: 0; page-break-after: always; break-after: page; }
        @endif
        @media print { body { background: #fff; } .bar, .warn { display: none !important; } .sheet { gap: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Print {{ count($labels) }} label(s)</button>
        <span>Set the printer's paper to <strong>{{ $size['sheet'] ? 'A4' : $size['w'].' × '.$size['h'] }}</strong>, margins "None", scale 100%.</span>
    </div>
    @foreach($skipped as $s)<div class="warn">Skipped: {{ $s }}</div>@endforeach
    <div class="sheet">
        @foreach($labels as $l)
            <div class="label">
                <div class="name">{{ $l['name'] }}</div>
                <div class="code">{!! $l['svg'] !!}</div>
                <div class="foot"><span>{{ $l['value'] }}</span>@if($l['price'] !== null)<span class="price">{{ $currency }} {{ $l['price'] }}</span>@endif</div>
            </div>
        @endforeach
    </div>
    @if(! count($labels))<p style="padding:1rem">Nothing to print.</p>@endif
</body>
</html>
