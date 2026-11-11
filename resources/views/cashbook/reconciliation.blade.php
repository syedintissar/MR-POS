@php use App\Support\Money; use App\Support\Decimal; @endphp
@php
    // after a refused "close", the ticks the person sent come back with the page
    $sent = old('lines');
    $rows = array_map(fn ($l) => ['key' => $l['key'], 'in' => (float) $l['money_in'], 'out' => (float) $l['money_out'], 'on' => is_array($sent) ? in_array($l['key'], $sent, true) : $l['ticked']], $lines);
@endphp
<x-layouts.app :title="$r->reconciliation_no">
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $r->reconciliation_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $r->account->name }} · statement up to {{ $r->statement_date->format('d M Y') }}</h2>
                <div class="mt-1 text-sm text-slate-500">Started by {{ $r->creator?->name ?? 'system' }} on {{ $r->created_at->format('d M Y, H:i') }}@if($r->notes) · {{ $r->notes }}@endif</div>
            </div>
            @if($r->isVoided())<span class="badge badge-red text-sm">Cancelled</span>
            @elseif($r->isCompleted())<span class="badge badge-green text-sm">Closed</span>
            @else<span class="badge badge-amber text-sm">Being matched</span>@endif
        </div>

        @if($r->isOpen())
            <form method="POST" action="{{ route('cashbook.reconciliations.save', $r) }}"
                  x-data="{
                      rows: @js($rows), opening: {{ (float) $totals['opening'] }}, statement: {{ (float) $totals['statement'] }},
                      sum(f) { return this.rows.reduce((t, x) => t + (x.on ? x[f] : 0), 0) },
                      matched() { return Math.round((this.opening + this.sum('in') - this.sum('out')) * 100) / 100 },
                      difference() { return Math.round((this.statement - this.matched()) * 100) / 100 },
                      money(v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
                      all(on) { this.rows.forEach(x => x.on = on) },
                  }">
                @csrf @method('PUT')
                <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Statement opened with</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::format($totals['opening']) }}</div></div>
                    <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ticked money in</div><div class="mt-1 text-lg font-semibold text-emerald-700" x-text="money(sum('in'))"></div></div>
                    <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ticked money out</div><div class="mt-1 text-lg font-semibold text-red-700" x-text="money(sum('out'))"></div></div>
                    <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Statement closes with</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::format($totals['statement']) }}</div></div>
                    <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Still to find</div>
                        <div class="mt-1 text-lg font-semibold" :class="difference() === 0 ? 'text-emerald-700' : 'text-red-700'" x-text="difference() === 0 ? 'Nothing: it agrees' : money(difference())"></div></div>
                </div>

                <div class="card overflow-x-auto">
                    <div class="card-header flex flex-wrap items-center justify-between gap-2">
                        <h3 class="card-title">Lines of the book up to {{ $r->statement_date->format('d M Y') }} not matched before</h3>
                        <div class="flex gap-2"><button type="button" class="btn btn-secondary btn-sm" @click="all(true)" data-no-loading>Tick all</button><button type="button" class="btn btn-secondary btn-sm" @click="all(false)" data-no-loading>Untick all</button></div>
                    </div>
                    <table class="table">
                        <thead><tr><th class="w-10">On it</th><th>Date</th><th>Document</th><th>What</th><th>Who / where</th><th class="text-right">Money in</th><th class="text-right">Money out</th></tr></thead>
                        <tbody>
                        @forelse($lines as $i => $l)
                            <tr :class="rows[{{ $i }}].on ? 'bg-emerald-50' : ''">
                                <td><input type="checkbox" name="lines[]" value="{{ $l['key'] }}" x-model="rows[{{ $i }}].on" class="h-4 w-4" aria-label="{{ $l['doc_no'] }} is on the statement"></td>
                                <td class="whitespace-nowrap">{{ date('d M Y', strtotime($l['date'])) }}</td>
                                <td class="font-mono text-xs font-medium">{{ $l['doc_no'] }}</td>
                                <td>{{ $l['what'] }}</td>
                                <td class="max-w-xs truncate">{{ $l['party'] }}</td>
                                <td class="text-right text-emerald-700">{{ Decimal::isZero($l['money_in']) ? '' : Money::format($l['money_in']) }}</td>
                                <td class="text-right text-red-700">{{ Decimal::isZero($l['money_out']) ? '' : Money::format($l['money_out']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">The book of this account has no unmatched line up to that day.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @can('cashbook.reconcile')
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <p class="max-w-2xl text-xs text-slate-500">Something on the statement that is not in the list (a bank charge, interest)? Save the ticks, record it (an expense paid by a method of this account, or a cash &amp; bank entry), then come back.</p>
                        <div class="flex gap-2">
                            <button class="btn btn-secondary" data-loading-text="Saving…">Save the ticks</button>
                            <button name="complete" value="1" class="btn btn-primary" :disabled="difference() !== 0" data-loading-text="Closing…">Close the statement</button>
                        </div>
                    </div>
                @endcan
            </form>
        @else
            <div class="grid gap-4 sm:grid-cols-4">
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Opened with</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::format($totals['opening']) }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Matched money in</div><div class="mt-1 text-lg font-semibold text-emerald-700">{{ Money::format($totals['money_in']) }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Matched money out</div><div class="mt-1 text-lg font-semibold text-red-700">{{ Money::format($totals['money_out']) }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Closed with</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::format($totals['statement']) }}</div></div>
            </div>

            @if($r->isCompleted())
                <p class="text-sm text-slate-600">Closed by {{ $r->completer?->name }} on {{ $r->completed_at->format('d M Y, H:i') }}. Cash &amp; bank entries for {{ $r->account->name }} can no longer be dated on or before {{ $r->statement_date->format('d M Y') }}.</p>
                <div class="card overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Document</th><th class="text-right">Money in</th><th class="text-right">Money out</th></tr></thead>
                        <tbody>
                        @foreach($matched as $m)
                            <tr><td class="whitespace-nowrap">{{ date('d M Y', strtotime($m->line_date)) }}</td><td class="font-mono text-xs font-medium">{{ $m->doc_no }}</td>
                                <td class="text-right text-emerald-700">{{ Decimal::isZero((string) $m->money_in) ? '' : Money::format($m->money_in) }}</td><td class="text-right text-red-700">{{ Decimal::isZero((string) $m->money_out) ? '' : Money::format($m->money_out) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Cancelled by {{ $r->voider?->name }} on {{ $r->voided_at->format('d M Y, H:i') }}: “{{ $r->void_reason }}”. Its lines are open again.</div>
            @endif
        @endif

        @unless($r->isVoided())
            @can('cashbook.void')
                <form method="POST" action="{{ route('cashbook.reconciliations.void', $r) }}" class="card card-body space-y-3" data-confirm="Cancel {{ $r->reconciliation_no }}? Its lines will be open again." data-confirm-label="Cancel matching" data-danger="1">
                    @csrf
                    <h3 class="card-title">Wrong statement or wrong balance?</h3>
                    <p class="text-sm text-slate-600">Cancelling keeps the record and opens its lines again. Only the latest statement of an account can be cancelled.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this matching</button></div>
                </form>
            @endcan
        @endunless

        <a href="{{ route('cashbook.reconciliations.index') }}" class="inline-block text-sm">← Bank statements</a>
    </div>
</x-layouts.app>
