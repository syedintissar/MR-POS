@php use App\Support\Money; use App\Models\MoneyTransaction; @endphp
<x-layouts.app title="Cash & bank entries">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="type">Kind</label>
                <select id="type" name="type" class="input w-48"><option value="">All</option>@foreach(MoneyTransaction::LABELS as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label" for="account">Account</label>
                <select id="account" name="account" class="input w-44"><option value="">Any</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((int) request('account') === $a->id)>{{ $a->name }}</option>@endforeach</select></div>
            <div><label class="label" for="status">Status</label>
                <select id="status" name="status" class="input w-32">@foreach(['posted' => 'Recorded', 'voided' => 'Cancelled', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label" for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label" for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        @canany(['cashbook.transact', 'cashbook.manage'])<a href="{{ route('cashbook.entries.create') }}" class="btn btn-primary">New entry</a>@endcanany
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>No.</th><th>Date</th><th>What</th><th>From</th><th>To</th><th class="text-right">Amount</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse($entries as $e)
                <tr class="{{ $e->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('cashbook.entries.show', $e) }}">{{ $e->txn_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $e->txn_date->format('d M Y') }}</td>
                    <td>{{ $e->label() }}<div class="max-w-xs truncate text-xs text-slate-500">{{ $e->description }}</div></td>
                    <td>{{ $e->from?->name }}</td>
                    <td>{{ $e->to?->name }}</td>
                    <td class="text-right font-medium {{ $e->isVoided() ? 'line-through' : '' }}">{{ Money::format($e->amount) }}</td>
                    <td>{{ $e->creator?->name }}</td>
                    <td class="text-right">@if($e->isVoided())<span class="badge badge-red">Cancelled</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No entries found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $entries->links() }}</div>
    <a href="{{ route('cashbook.index') }}" class="mt-4 inline-block text-sm">← Cash &amp; bank</a>
</x-layouts.app>
