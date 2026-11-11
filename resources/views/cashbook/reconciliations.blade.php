@php use App\Support\Money; @endphp
<x-layouts.app title="Bank statements">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">When the bank or wallet statement arrives, tick every line of the book that is on it. When the ticked lines add up to the statement's closing balance, the statement is closed. Lines that are not on it yet stay open for the next statement.</p>

    @can('cashbook.reconcile')
        @if($accounts->isNotEmpty())
            <form method="POST" action="{{ route('cashbook.reconciliations.store') }}" class="card mb-6">
                @csrf
                <div class="card-header"><h2 class="card-title">Match a new statement</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-4">
                    <div><label class="label" for="money_account_id">Account <span class="text-red-500">*</span></label>
                        <select id="money_account_id" name="money_account_id" class="input" required>
                            @foreach($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('money_account_id') === $a->id)>{{ $a->name }} (book: {{ Money::withSymbol($balances[$a->id] ?? '0') }})</option>@endforeach
                        </select></div>
                    <x-form.input name="statement_date" label="Statement up to" type="date" :max="now()->toDateString()" required help="The last day the statement covers" />
                    <x-form.input name="statement_balance" label="Closing balance on it (Rs.)" type="number" step="0.01" required help="Minus if overdrawn" />
                    <x-form.input name="notes" label="Note" maxlength="255" />
                </div>
                <div class="flex justify-end border-t border-slate-100 px-5 py-3"><button class="btn btn-primary">Start matching</button></div>
            </form>
        @endif
    @endcan

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>No.</th><th>Account</th><th>Statement up to</th><th class="text-right">Opening</th><th class="text-right">Closing on statement</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse($reconciliations as $r)
                <tr class="{{ $r->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('cashbook.reconciliations.show', $r) }}">{{ $r->reconciliation_no }}</a></td>
                    <td>{{ $r->account->name }}</td>
                    <td class="whitespace-nowrap">{{ $r->statement_date->format('d M Y') }}</td>
                    <td class="text-right">{{ Money::format($r->opening_balance) }}</td>
                    <td class="text-right font-medium">{{ Money::format($r->statement_balance) }}</td>
                    <td>{{ $r->creator?->name }}</td>
                    <td class="text-right">
                        @if($r->isVoided())<span class="badge badge-red">Cancelled</span>
                        @elseif($r->isCompleted())<span class="badge badge-green">Closed</span>
                        @else<span class="badge badge-amber">Being matched</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">No statement has been matched yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $reconciliations->links() }}</div>
    <a href="{{ route('cashbook.index') }}" class="mt-4 inline-block text-sm">← Cash &amp; bank</a>
</x-layouts.app>
