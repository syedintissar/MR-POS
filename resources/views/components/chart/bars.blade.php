@props(['labels', 'series', 'height' => 190, 'title' => 'Chart', 'money' => true])
@php
    // series: [['name' => 'Sales', 'values' => ['10.00', ...], 'color' => '#c0121b'], ...]
    $n = max(1, count($labels));
    $k = max(1, count($series));
    $all = [];
    foreach ($series as $s) { foreach ($s['values'] as $v) { $all[] = (float) $v; } }
    $max = max(0.0, ...($all ?: [0.0]));
    $min = min(0.0, ...($all ?: [0.0]));
    $range = ($max - $min) > 0 ? ($max - $min) : 1.0;

    $left = 46; $right = 8; $top = 10; $bottom = 24;
    $slot = max(10, min(38, 640 / $n));
    $width = $left + $right + $slot * $n;
    $plotH = $height - $top - $bottom;
    $y = fn (float $v) => $top + $plotH * (($max - $v) / $range);
    $zero = $y(0.0);
    $barW = max(2.5, ($slot - 6) / $k);
    $step = (int) ceil($n / 8);

    $compact = function (float $v): string {
        $a = abs($v);
        $s = $a >= 1000000 ? rtrim(rtrim(number_format($a / 1000000, 1), '0'), '.').'M' : ($a >= 1000 ? rtrim(rtrim(number_format($a / 1000, 1), '0'), '.').'k' : number_format($a));

        return ($v < 0 ? '-' : '').$s;
    };
    $full = fn (float $v): string => $money ? \App\Support\Money::format((string) $v) : number_format($v);
    $ticks = [$max, $min < 0 ? ($max + $min) / 2 : $max / 2, $min];
@endphp
<div {{ $attributes->class('w-full') }}>
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-auto w-full" role="img" aria-label="{{ $title }}" preserveAspectRatio="xMidYMid meet">
        @foreach(array_unique($ticks) as $t)
            <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ round($y((float) $t), 1) }}" y2="{{ round($y((float) $t), 1) }}" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3 3"/>
            <text x="{{ $left - 6 }}" y="{{ round($y((float) $t) + 3.5, 1) }}" text-anchor="end" font-size="9.5" fill="#64748b">{{ $compact((float) $t) }}</text>
        @endforeach
        <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ round($zero, 1) }}" y2="{{ round($zero, 1) }}" stroke="#94a3b8" stroke-width="1"/>
        @foreach($labels as $i => $label)
            @foreach($series as $j => $s)
                @php
                    $v = (float) ($s['values'][$i] ?? 0);
                    $x = $left + $slot * $i + ($slot - $barW * $k) / 2 + $barW * $j;
                    $yy = $y(max($v, 0.0));
                    $h = abs($y($v) - $zero);
                @endphp
                @if($h > 0)
                    <rect class="chart-bar" x="{{ round($x, 1) }}" y="{{ round($v >= 0 ? $yy : $zero, 1) }}" width="{{ round($barW - 1.5, 1) }}" height="{{ round(max($h, 1.5), 1) }}" rx="2.5"
                          fill="{{ $v < 0 ? '#94a3b8' : $s['color'] }}" style="animation-delay: {{ min(0.5, $i * 0.012) }}s"><title>{{ $label }} · {{ $s['name'] }}: {{ $full($v) }}</title></rect>
                @endif
            @endforeach
            @if($i % $step === 0 || $i === $n - 1)
                <text x="{{ round($left + $slot * $i + $slot / 2, 1) }}" y="{{ $height - 7 }}" text-anchor="middle" font-size="9.5" fill="#64748b">{{ $label }}</text>
            @endif
        @endforeach
    </svg>
    @if(count($series) > 1)
        <div class="mt-1 flex flex-wrap gap-4 text-xs text-slate-500">
            @foreach($series as $s)<span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $s['color'] }}"></span>{{ $s['name'] }}</span>@endforeach
        </div>
    @endif
</div>
