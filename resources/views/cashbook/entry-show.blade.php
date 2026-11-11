@php use App\Support\Money; @endphp
<x-layouts.app :title="$entry->txn_no">
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $entry->txn_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ Money::withSymbol($entry->amount) }} · {{ $entry->label() }}</h2>
                <div class="mt-1 text-sm text-slate-500">{{ $entry->txn_date->format('d M Y') }}@if($entry->reference_no) · ref. {{ $entry->reference_no }}@endif · entered by {{ $entry->creator?->name ?? 'system' }} on {{ $entry->created_at->format('d M Y, H:i') }}</div>
            </div>
            @if($entry->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@else<span class="badge badge-green text-sm">Recorded</span>@endif
        </div>

        <div class="card card-body grid gap-4 sm:grid-cols-2">
            <div><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Money left</div>
                <div class="mt-1 text-sm text-slate-800">@if($entry->from)<a href="{{ route('cashbook.accounts.show', $entry->from) }}">{{ $entry->from->name }}</a>@else — @endif</div></div>
            <div><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Money went into</div>
                <div class="mt-1 text-sm text-slate-800">@if($entry->to)<a href="{{ route('cashbook.accounts.show', $entry->to) }}">{{ $entry->to->name }}</a>@else — @endif</div></div>
            <div class="sm:col-span-2"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Note</div><p class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $entry->description }}</p></div>
        </div>

        @if($entry->isFromCount() && $entry->closing)
            <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">This entry was written by day closing <a href="{{ route('cashbook.closings.show', $entry->closing) }}" class="font-semibold">{{ $entry->closing->closing_no }}</a>. To undo it, cancel that day closing.</div>
        @endif

        @if($entry->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Cancelled by {{ $entry->voider?->name }} on {{ $entry->voided_at->format('d M Y, H:i') }}: “{{ $entry->void_reason }}”. It no longer counts in any balance.</div>
        @elseif(! $entry->isFromCount())
            @can('cashbook.void')
                <form method="POST" action="{{ route('cashbook.entries.void', $entry) }}" class="card card-body space-y-3" data-confirm="Cancel entry {{ $entry->txn_no }}?" data-confirm-label="Cancel entry" data-danger="1">
                    @csrf
                    <h3 class="card-title">Entered by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling keeps the record but removes it from the balances. Then enter the correct one.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this entry</button></div>
                </form>
            @endcan
        @endif

        <a href="{{ route('cashbook.entries.index') }}" class="inline-block text-sm">← All entries</a>
    </div>
</x-layouts.app>
