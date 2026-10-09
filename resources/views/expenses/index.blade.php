@php use App\Support\Money; @endphp
<x-layouts.app title="Expenses">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="q">Find</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="EXP-000123, payee, note" class="input w-52"></div>
            <div><label class="label" for="category">Category</label>
                <select id="category" name="category" class="input w-44"><option value="">All</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected((int) request('category') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div><label class="label" for="method">Paid by</label>
                <select id="method" name="method" class="input w-36"><option value="">Any</option>@foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) request('method') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
            <div><label class="label" for="status">Status</label>
                <select id="status" name="status" class="input w-32">@foreach(['posted' => 'Recorded', 'voided' => 'Cancelled', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label" for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label" for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        <div class="flex gap-2">
            <a href="{{ route('expenses.categories') }}" class="btn btn-secondary">Categories</a>
            @can('expenses.create')<a href="{{ route('expenses.create') }}" class="btn btn-primary">Record an expense</a>@endcan
        </div>
    </div>

    <div class="mb-4 card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recorded expenses in this list</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($total) }}</div></div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>No.</th><th>Date</th><th>Category</th><th>Payee / note</th><th>Paid by</th><th class="text-right">Amount</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse($expenses as $e)
                <tr class="{{ $e->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('expenses.show', $e) }}">{{ $e->expense_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $e->expense_date->format('d M Y') }}</td>
                    <td>{{ $e->category->name }}</td>
                    <td class="max-w-xs truncate">{{ $e->payee ?: '' }}<div class="truncate text-xs text-slate-500">{{ $e->description }}</div></td>
                    <td>{{ $e->method->name }}</td>
                    <td class="text-right font-medium {{ $e->isVoided() ? 'line-through' : '' }}">{{ Money::format($e->amount) }}</td>
                    <td>{{ $e->creator?->name }}</td>
                    <td class="text-right">@if($e->isVoided())<span class="badge badge-red">Cancelled</span>@endif @if($e->attachment_path)<span title="Has a bill or receipt attached">📎</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No expenses found. @can('expenses.create')<a href="{{ route('expenses.create') }}">Record the first one.</a>@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $expenses->links() }}</div>
</x-layouts.app>
