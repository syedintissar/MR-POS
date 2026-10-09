<x-layouts.app title="Expense categories">
    <div class="mx-auto max-w-2xl space-y-4">
        @can('expenses.edit')
            <form method="POST" action="{{ route('expenses.categories.store') }}" class="card card-body flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1"><label class="label" for="name">New category</label><input id="name" name="name" type="text" maxlength="100" required class="input" placeholder="Insurance, packaging…">@error('name')<p class="error-text">{{ $message }}</p>@enderror</div>
                <button class="btn btn-primary" data-loading-text="Saving…">Add</button>
            </form>
        @endcan
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Category</th><th class="text-right">Expenses</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach($categories as $c)
                    <tr class="{{ $c->is_active ? '' : 'text-slate-400' }}">
                        <td class="font-medium">
                            @can('expenses.edit')
                                <form method="POST" action="{{ route('expenses.categories.update', $c) }}" class="flex items-center gap-2">@csrf @method('PUT')
                                    <input name="name" value="{{ $c->name }}" maxlength="100" class="input h-8 py-1" aria-label="Category name"><button class="btn btn-secondary btn-sm">Rename</button></form>
                            @else{{ $c->name }}@endcan
                        </td>
                        <td class="text-right">{{ $c->expenses_count }}</td>
                        <td>@if($c->is_active)<span class="badge badge-green">on</span>@else<span class="badge badge-gray">off</span>@endif</td>
                        <td class="text-right">@can('expenses.edit')<form method="POST" action="{{ route('expenses.categories.toggle', $c) }}">@csrf<button class="btn btn-secondary btn-sm">{{ $c->is_active ? 'Switch off' : 'Switch on' }}</button></form>@endcan</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <a href="{{ route('expenses.index') }}" class="inline-block text-sm">← All expenses</a>
    </div>
</x-layouts.app>
