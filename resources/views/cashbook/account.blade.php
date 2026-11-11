@php use App\Support\Money; use App\Support\Decimal; @endphp
@php
    $user = auth()->user();
    $links = [
        'customer_payment' => $user->can('customer_payments.view') ? 'customer-payments.show' : null,
        'supplier_payment' => $user->can('supplier_payments.view') ? 'supplier-payments.show' : null,
        'expense' => $user->can('expenses.view') ? 'expenses.show' : null,
        'money_transaction' => 'cashbook.entries.show',
    ];
@endphp
<x-layouts.app :title="$account->name">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="from">From</label><input id="from" type="date" name="from" value="{{ $from }}" max="{{ now()->toDateString() }}" class="input w-40"></div>
            <div><label class="label" for="to">To</label><input id="to" type="date" name="to" value="{{ $to }}" max="{{ now()->toDateString() }}" class="input w-40"></div>
            <button class="btn btn-secondary" data-no-loading>Show</button>
        </form>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('cashbook.accounts.print', [$account, 'from' => $from, 'to' => $to]) }}" target="_blank" class="btn btn-secondary" data-no-loading>Print</a>
            @can('cashbook.transact')<a href="{{ route('cashbook.entries.create', ['account' => $account->id]) }}" class="btn btn-secondary">Move money</a>@endcan
            @if($account->isCash() && $account->is_active) @can('cashbook.close_day')<a href="{{ route('cashbook.closings.create', ['account' => $account->id]) }}" class="btn btn-primary">Close the day</a>@endcan @endif
        </div>
    </div>

    <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Brought forward</div><div class="mt-1 text-xl font-semibold text-slate-900">{{ Money::withSymbol($book['opening']) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Money in</div><div class="mt-1 text-xl font-semibold text-emerald-700">{{ Money::withSymbol($book['money_in']) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Money out</div><div class="mt-1 text-xl font-semibold text-red-700">{{ Money::withSymbol($book['money_out']) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">At the end of {{ date('d M Y', strtotime($to)) }}</div><div class="mt-1 text-xl font-semibold text-slate-900">{{ Money::withSymbol($book['closing']) }}</div>
            @if(Decimal::cmp($now, $book['closing']) !== 0)<div class="mt-1 text-xs text-slate-500">Now: {{ Money::withSymbol($now) }}</div>@endif</div>
    </div>

    <p class="mb-3 text-xs text-slate-500">{{ $account->kindLabel() }} account{{ $account->account_number ? ' · '.$account->account_number : '' }} ·
        @if($methods !== [])payment methods paying into it: {{ implode(', ', $methods) }}@else no payment method pays into it @endif</p>

    @if($book['truncated'])
        <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Only the first {{ number_format(\App\Services\CashBook\MoneyAccountService::MAX_ROWS) }} movements are listed; the totals above cover the whole period. Choose a shorter period to see every line.</div>
    @endif

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Date</th><th>Document</th><th>What</th><th>Who / where</th><th class="text-right">Money in</th><th class="text-right">Money out</th><th class="text-right">Balance</th></tr></thead>
            <tbody>
                <tr class="bg-slate-50 text-slate-600"><td class="whitespace-nowrap">{{ date('d M Y', strtotime($from)) }}</td><td></td><td colspan="4">Balance brought forward</td><td class="text-right font-medium">{{ Money::format($book['opening']) }}</td></tr>
                @forelse($book['rows'] as $r)
                    <tr>
                        <td class="whitespace-nowrap">{{ date('d M Y', strtotime($r['date'])) }}</td>
                        <td class="font-mono text-xs font-medium">@if($links[$r['source']] ?? null)<a href="{{ route($links[$r['source']], $r['source_id']) }}">{{ $r['doc_no'] }}</a>@else{{ $r['doc_no'] }}@endif</td>
                        <td>{{ $r['what'] }}</td>
                        <td class="max-w-xs truncate">{{ $r['party'] }}@if($r['note'])<div class="truncate text-xs text-slate-500">{{ $r['note'] }}</div>@endif</td>
                        <td class="text-right text-emerald-700">{{ $r['money_in'] !== null ? Money::format($r['money_in']) : '' }}</td>
                        <td class="text-right text-red-700">{{ $r['money_out'] !== null ? Money::format($r['money_out']) : '' }}</td>
                        <td class="text-right font-medium {{ Decimal::isNegative($r['balance']) ? 'text-red-700' : '' }}">{{ Money::format($r['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No money moved in this account in these dates.</td></tr>
                @endforelse
            </tbody>
            @if($book['rows'] !== [])
                <tfoot><tr class="font-semibold"><td colspan="4">Total and closing balance</td><td class="text-right">{{ Money::format($book['money_in']) }}</td><td class="text-right">{{ Money::format($book['money_out']) }}</td><td class="text-right">{{ Money::format($book['closing']) }}</td></tr></tfoot>
            @endif
        </table>
    </div>

    <a href="{{ route('cashbook.index') }}" class="mt-4 inline-block text-sm">← Cash &amp; bank</a>
</x-layouts.app>
