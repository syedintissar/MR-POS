@php use App\Support\Money; use App\Support\Decimal; use App\Models\DayClosing; @endphp
<x-layouts.app :title="$closing->closing_no">
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3 print:hidden">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $closing->closing_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $closing->account->name }} · {{ $closing->closed_at->format('d M Y, H:i') }}</h2>
                <div class="mt-1 text-sm text-slate-500">Counted by {{ $closing->creator?->name ?? 'system' }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($closing->isVoided())<span class="badge badge-red text-sm">Cancelled</span>
                @elseif($closing->result() === 'exact')<span class="badge badge-green text-sm">Exact</span>
                @elseif($closing->result() === 'short')<span class="badge badge-red text-sm">Short</span>
                @else<span class="badge badge-amber text-sm">Over</span>@endif
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()" data-no-loading>Print</button>
            </div>
        </div>

        <div class="card card-body">
            <div class="mb-3 hidden print:block"><div class="text-lg font-semibold">{{ $shop }} · Day closing {{ $closing->closing_no }}</div>
                <div class="text-sm">{{ $closing->account->name }} · {{ $closing->closed_at->format('d M Y, H:i') }} · counted by {{ $closing->creator?->name }}{{ $closing->isVoided() ? ' · CANCELLED' : '' }}</div></div>

            @if($closing->denominations)
                <table class="table mb-4">
                    <thead><tr><th>Note</th><th class="text-right">How many</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                    @foreach(DayClosing::NOTES as $n)
                        @if(! empty($closing->denominations[(string) $n]))
                            <tr><td>Rs. {{ number_format($n) }}</td><td class="text-right">{{ number_format((int) $closing->denominations[(string) $n]) }}</td><td class="text-right">{{ Money::format(Decimal::mul((string) $n, (string) $closing->denominations[(string) $n], 2)) }}</td></tr>
                        @endif
                    @endforeach
                    @if(! empty($closing->denominations['coins']))<tr><td>Coins and small notes</td><td></td><td class="text-right">{{ Money::format($closing->denominations['coins']) }}</td></tr>@endif
                    </tbody>
                </table>
            @else
                <p class="mb-4 text-sm text-slate-500">The total was typed; the notes were not counted one kind at a time.</p>
            @endif

            <div class="space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-slate-600">The book said</span><span>{{ Money::withSymbol($closing->expected_amount) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-600">Counted</span><span class="font-semibold">{{ Money::withSymbol($closing->counted_amount) }}</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold {{ $closing->result() === 'exact' ? 'text-emerald-700' : ($closing->result() === 'short' ? 'text-red-700' : 'text-amber-700') }}">
                    <span>{{ ucfirst($closing->result()) }}</span><span>{{ Money::withSymbol(Decimal::abs((string) $closing->difference, 2)) }}</span>
                </div>
            </div>

            @if($closing->notes)<div class="mt-4"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Explanation</div><p class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $closing->notes }}</p></div>@endif
            @if($closing->adjustment)<p class="mt-3 text-xs text-slate-500 print:hidden">The difference is in the book as <a href="{{ route('cashbook.entries.show', $closing->adjustment) }}">{{ $closing->adjustment->txn_no }}</a>.</p>@endif

            <div class="mt-10 hidden grid-cols-2 gap-10 text-sm print:grid"><div class="border-t border-slate-400 pt-1">Counted by</div><div class="border-t border-slate-400 pt-1">Checked by</div></div>
        </div>

        <div class="print:hidden">
            @if($closing->isVoided())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Cancelled by {{ $closing->voider?->name }} on {{ $closing->voided_at->format('d M Y, H:i') }}: “{{ $closing->void_reason }}”. Its difference no longer counts in the book.</div>
            @elseif($isLatest)
                @can('cashbook.void')
                    <form method="POST" action="{{ route('cashbook.closings.void', $closing) }}" class="card card-body space-y-3" data-confirm="Cancel day closing {{ $closing->closing_no }}?" data-confirm-label="Cancel closing" data-danger="1">
                        @csrf
                        <h3 class="card-title">Counted or typed wrongly?</h3>
                        <p class="text-sm text-slate-600">Cancelling keeps the record, takes its over / short entry out of the book, and lets you count again.</p>
                        <x-form.input name="reason" label="Reason for cancelling" required />
                        <div><button class="btn btn-danger">Cancel this day closing</button></div>
                    </form>
                @endcan
            @else
                <p class="text-xs text-slate-500">A later count of this drawer exists, so this one can no longer be cancelled.</p>
            @endif

            <a href="{{ route('cashbook.closings.index') }}" class="mt-4 inline-block text-sm">← Day closings</a>
        </div>
    </div>
</x-layouts.app>
