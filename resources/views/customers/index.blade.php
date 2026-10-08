@php
    use App\Support\Money;
@endphp
<x-layouts.app title="Customers">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone, code or company" class="input w-64"></div>
            <div><label class="label">Status</label>
                <select name="status" class="input w-32">@foreach(['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected(request('status', 'active') === $k)>{{ $v }}</option>@endforeach</select></div>
            @if($money)
                <div><label class="label">Account</label>
                    <select name="balance" class="input w-40">@foreach(['all' => 'All', 'owing' => 'Owe us money', 'credit' => 'Hold credit with us'] as $k => $v)<option value="{{ $k }}" @selected(request('balance', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            @endif
            <button class="btn btn-secondary" data-no-loading>Search</button>
        </form>
        <div class="flex gap-2">
            @can('customer_payments.create')<a href="{{ route('customer-payments.create') }}" class="btn btn-secondary">Collect a payment</a>@endcan
            @can('customers.create')<a href="{{ route('customers.create') }}" class="btn btn-primary">+ New customer</a>@endcan
        </div>
    </div>

    @if($money && $totals)
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Owed to you</div><div class="mt-1 text-2xl font-semibold {{ (float) $totals->owed > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ Money::withSymbol($totals->owed) }}</div><div class="text-xs text-slate-500">{{ (int) $totals->debtors }} customer(s)</div></div>
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customers' money you hold</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($totals->held) }}</div><div class="text-xs text-slate-500">paid in advance or credited</div></div>
            @can('reports.customers')<a href="{{ route('receivables.index') }}" class="card card-body block hover:border-brand-500"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Who owes us, and for how long</div><div class="mt-1 text-sm text-slate-700">Open the aging report →</div></a>@endcan
        </div>
    @endif

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Code</th><th>Customer</th><th>Phone</th><th>Prices</th><th>Credit</th>@if($money)<th class="text-right">Balance</th>@endif<th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($customers as $c)
                <tr>
                    <td class="font-mono text-xs text-slate-600">{{ $c->code }}</td>
                    <td><a href="{{ route('customers.show', $c) }}" class="font-medium text-slate-900">{{ $c->name }}</a>@if($c->company)<div class="text-xs text-slate-500">{{ $c->company }}</div>@endif</td>
                    <td class="whitespace-nowrap">{{ $c->phone }}</td>
                    <td>{{ $c->price_tier === 'wholesale' ? 'Wholesale' : 'Retail' }}</td>
                    <td class="text-slate-600">{{ $c->allow_credit ? ($c->credit_limit !== null ? 'up to '.Money::format($c->credit_limit) : 'no limit') : 'no credit' }}</td>
                    @if($money)
                        <td class="whitespace-nowrap text-right font-medium {{ (float) $c->balance > 0 ? 'text-amber-700' : ((float) $c->balance < 0 ? 'text-blue-700' : 'text-slate-400') }}"
                            title="{{ (float) $c->balance > 0 ? 'Owes you' : ((float) $c->balance < 0 ? 'You hold their credit' : '') }}">{{ (float) $c->balance == 0 ? '—' : Money::format(abs((float) $c->balance)) }}</td>
                    @endif
                    <td>@if($c->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-gray">Inactive</span>@endif</td>
                    <td class="text-right"><a href="{{ route('customers.show', $c) }}" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No customers found. @can('customers.create')<a href="{{ route('customers.create') }}">Add one</a>.@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $customers->links() }}</div>
</x-layouts.app>
