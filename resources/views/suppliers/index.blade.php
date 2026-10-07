<x-layouts.app title="Suppliers">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-wrap gap-2">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, company, phone or code" class="input w-64">
            <select name="status" class="input w-32">
                @foreach(['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected(request('status', 'active') === $k)>{{ $v }}</option>@endforeach
            </select>
            <button class="btn btn-secondary" data-no-loading>Search</button>
        </form>
        @can('suppliers.create')<a href="{{ route('suppliers.create') }}" class="btn btn-primary">+ New supplier</a>@endcan
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Code</th><th>Supplier</th><th>Contact</th><th>Phone</th><th class="text-right">Products</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($suppliers as $s)
                <tr>
                    <td class="font-mono text-xs text-slate-600">{{ $s->code }}</td>
                    <td><span class="font-medium text-slate-900">{{ $s->name }}</span>@if($s->company)<div class="text-xs text-slate-500">{{ $s->company }}</div>@endif</td>
                    <td class="text-slate-600">{{ $s->contact_person }}</td>
                    <td class="whitespace-nowrap">{{ $s->phone }}</td>
                    <td class="text-right">{{ $s->products_count }}</td>
                    <td>@if($s->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-gray">Inactive</span>@endif</td>
                    <td class="text-right">@can('suppliers.edit')<a href="{{ route('suppliers.edit', $s) }}" class="btn btn-secondary btn-sm">Open</a>@endcan</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">No suppliers found. @can('suppliers.create')<a href="{{ route('suppliers.create') }}">Add one</a>.@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $suppliers->links() }}</div>
</x-layouts.app>
