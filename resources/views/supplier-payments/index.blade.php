<x-layouts.app title="Supplier payments">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Search</label><input type="search" name="q" value="{{ request('q') }}" placeholder="Number or reference" class="input w-44"></div>
            <div><label class="label">Supplier</label>
                <select name="supplier_id" class="input w-48"><option value="">All</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) request('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div><label class="label">Method</label>
                <select name="method" class="input w-40"><option value="">All</option>@foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) request('method') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
            <div><label class="label">Status</label>
                <select name="status" class="input w-32">@foreach(['all' => 'All', 'posted' => 'Recorded', 'voided' => 'Cancelled'] as $k => $v)<option value="{{ $k }}" @selected(request('status', 'all') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label">From</label><input type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label">To</label><input type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        <div class="flex gap-2">
            @can('purchases.view')<a href="{{ route('purchases.index') }}" class="btn btn-secondary">Purchases</a>@endcan
            @can('supplier_payments.create')<a href="{{ route('supplier-payments.create') }}" class="btn btn-primary">+ Pay a supplier</a>@endcan
        </div>
    </div>

    <div class="mb-4 card card-body"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total of the recorded payments listed</span>
        <span class="text-2xl font-semibold text-slate-900">{{ \App\Support\Money::withSymbol($total) }}</span></div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Number</th><th>Date</th><th>Supplier</th><th>Method</th><th>Reference</th><th class="text-right">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($payments as $p)
                <tr class="{{ $p->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('supplier-payments.show', $p) }}">{{ $p->payment_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $p->payment_date->format('d M Y') }}</td>
                    <td>@can('suppliers.view')<a href="{{ route('suppliers.show', $p->supplier_id) }}">{{ $p->supplier->name }}</a>@else{{ $p->supplier->name }}@endcan</td>
                    <td>{{ $p->method->name }}</td>
                    <td class="text-slate-600">{{ $p->reference_no }}</td>
                    <td class="text-right font-medium {{ $p->isVoided() ? 'line-through' : '' }}">{{ \App\Support\Money::format($p->amount) }}</td>
                    <td>@if($p->isVoided())<span class="badge badge-red">Cancelled</span>@else<span class="badge badge-green">Recorded</span>@endif</td>
                    <td class="text-right"><a href="{{ route('supplier-payments.show', $p) }}" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No supplier payments found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $payments->links() }}</div>
</x-layouts.app>
