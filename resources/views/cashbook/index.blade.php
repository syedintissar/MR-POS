@php use App\Support\Money; use App\Support\Decimal; @endphp
<x-layouts.app title="Cash & bank">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-2xl text-sm text-slate-600">Where the shop's money is. Every sale, payment and expense lands in the account of its payment method; the entries here record money that moved on its own.</p>
        <div class="flex flex-wrap gap-2">
            @can('cashbook.manage')<a href="{{ route('cashbook.setup') }}" class="btn btn-secondary">Accounts</a>@endcan
            <a href="{{ route('cashbook.reconciliations.index') }}" class="btn btn-secondary">Bank statements</a>
            <a href="{{ route('cashbook.closings.index') }}" class="btn btn-secondary">Day closings</a>
            <a href="{{ route('cashbook.entries.index') }}" class="btn btn-secondary">All entries</a>
            @can('cashbook.transact')<a href="{{ route('cashbook.entries.create') }}" class="btn btn-secondary">Move money</a>@endcan
            @can('cashbook.close_day')<a href="{{ route('cashbook.closings.create') }}" class="btn btn-primary">Close the day</a>@endcan
        </div>
    </div>

    @if($unlinked !== [])
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>Not in any account:</strong>
            @foreach($unlinked as $m){{ $m['name'] }}{{ $m['used'] ? ' (already used)' : '' }}{{ $loop->last ? '' : ', ' }}@endforeach.
            Money taken or paid with {{ count($unlinked) === 1 ? 'this method' : 'these methods' }} is in no balance below.
            @can('cashbook.manage')<a href="{{ route('cashbook.setup') }}" class="font-semibold underline">Choose the account</a>.@else Ask the administrator to choose the account.@endcan
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach($accounts as $a)
            @php($balance = $balances[$a->id] ?? '0.00')
            @php($last = $lastClosings[$a->id] ?? null)
            <a href="{{ route('cashbook.accounts.show', $a) }}" class="card card-body block text-slate-800 hover:text-slate-800 {{ $a->is_active ? '' : 'opacity-60' }}">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $a->name }}</div>
                    <span class="badge {{ $a->kind === 'cash' ? 'badge-green' : ($a->kind === 'bank' ? 'badge-blue' : 'badge-amber') }}">{{ $a->kindLabel() }}</span>
                </div>
                <div class="mt-1 text-2xl font-semibold {{ Decimal::isNegative($balance) ? 'text-red-700' : 'text-slate-900' }}">{{ Money::withSymbol($balance) }}</div>
                <div class="mt-1 text-xs text-slate-500">
                    @if(! $a->is_active)Switched off
                    @elseif($a->isCash())
                        @if($last)Last counted {{ $last->closed_at->format('d M Y, H:i') }}: {{ $last->result() === 'exact' ? 'exact' : ucfirst($last->result()).' '.Money::format(Decimal::abs((string) $last->difference, 2)) }}@else Never counted yet @endif
                    @else{{ $a->account_number ?: 'Open the book' }}
                    @endif
                </div>
            </a>
        @endforeach
    </div>

    <div class="card mt-6 overflow-x-auto">
        <div class="card-header"><h2 class="card-title">Latest entries</h2></div>
        <table class="table">
            <thead><tr><th>No.</th><th>Date</th><th>What</th><th>From</th><th>To</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
            @forelse($entries as $e)
                <tr>
                    <td class="font-mono text-xs font-medium"><a href="{{ route('cashbook.entries.show', $e) }}">{{ $e->txn_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $e->txn_date->format('d M Y') }}</td>
                    <td>{{ $e->label() }}<div class="max-w-xs truncate text-xs text-slate-500">{{ $e->description }}</div></td>
                    <td>{{ $e->from?->name }}</td>
                    <td>{{ $e->to?->name }}</td>
                    <td class="text-right font-medium">{{ Money::format($e->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No entries yet. Start by entering what each account holds today (Accounts → opening balance), then close the day every evening.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
