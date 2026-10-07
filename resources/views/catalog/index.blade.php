@php($canManage = auth()->user()->can('catalog.manage'))
<x-layouts.app title="Categories, brands & units">
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
            @foreach($tabs as $key => $label)
                <a href="{{ route('catalog.index', $key) }}"
                   class="rounded-md px-4 py-1.5 text-sm font-medium {{ $tab === $key ? 'bg-brand-600 text-white hover:text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">{{ $label }}</a>
            @endforeach
        </div>

        @if($canManage)
            <form method="POST" action="{{ route('catalog.store', $tab) }}" class="card card-body mb-4 grid gap-3 sm:grid-cols-6">
                @csrf
                @if($tab === 'categories')
                    <x-form.input name="name" label="New category" required class="sm:col-span-2" />
                    <x-form.select name="parent_id" label="Goes under" :options="['' => '— main category —'] + $roots->pluck('name', 'id')->all()" class="sm:col-span-2" />
                    <x-form.input name="sort_order" label="Order" type="number" value="0" />
                @elseif($tab === 'brands')
                    <x-form.input name="name" label="New brand" required class="sm:col-span-4" />
                @else
                    <x-form.input name="name" label="Unit name" required class="sm:col-span-2" placeholder="e.g. Metre" />
                    <x-form.input name="short_name" label="Short name" required placeholder="e.g. m" />
                    <x-form.checkbox name="allows_decimal" label="Allows decimals" :checked="false" help="For things sold by weight, volume or length." />
                @endif
                <div class="flex items-end"><button type="submit" class="btn btn-primary w-full">Add</button></div>
            </form>
        @endif

        <div class="card overflow-x-auto">
            @if($tab === 'categories')
                <table class="table">
                    <thead><tr><th>Category</th><th class="w-24">Products</th><th class="w-24">Status</th><th class="w-40"></th></tr></thead>
                    @forelse($roots as $root)
                        @foreach([$root, ...$root->children] as $cat)
                            <tbody x-data="{ edit: false }">
                                <tr x-show="!edit">
                                    <td class="{{ $cat->parent_id ? 'pl-10 text-slate-700' : 'font-medium text-slate-900' }}">{{ $cat->parent_id ? '↳ ' : '' }}{{ $cat->name }}</td>
                                    <td>{{ $cat->products_count }}</td>
                                    <td>@if($cat->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-gray">Hidden</span>@endif</td>
                                    <td class="text-right">
                                        @if($canManage)
                                            <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="edit = true">Edit</button>
                                            <form method="POST" action="{{ route('catalog.destroy', [$tab, $cat->id]) }}" class="inline" data-confirm="Delete the category “{{ $cat->name }}”?" data-confirm-label="Delete" data-danger="1">
                                                @csrf @method('DELETE')<button class="btn btn-secondary btn-sm text-red-600">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                                @if($canManage)
                                    <tr x-show="edit" x-cloak class="bg-slate-50">
                                        <td colspan="4">
                                            <form method="POST" action="{{ route('catalog.update', [$tab, $cat->id]) }}" class="grid gap-3 sm:grid-cols-6">
                                                @csrf @method('PUT')
                                                <x-form.input name="name" label="Name" :value="$cat->name" required :field="'name'" class="sm:col-span-2" />
                                                <x-form.select name="parent_id" label="Goes under" :field="'parent_id'" :options="['' => '— main category —'] + $roots->where('id', '!=', $cat->id)->pluck('name', 'id')->all()" :value="$cat->parent_id" class="sm:col-span-2" />
                                                <x-form.input name="sort_order" label="Order" type="number" :value="$cat->sort_order" :field="'sort_order'" />
                                                <x-form.checkbox name="is_active" label="Active" :checked="$cat->is_active" :field="'is_active'" />
                                                <div class="flex gap-2 sm:col-span-6"><button class="btn btn-primary btn-sm">Save</button><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="edit = false">Cancel</button></div>
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        @endforeach
                    @empty
                        <tbody><tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No categories yet.</td></tr></tbody>
                    @endforelse
                </table>
            @elseif($tab === 'brands')
                <table class="table">
                    <thead><tr><th>Brand</th><th class="w-24">Products</th><th class="w-24">Status</th><th class="w-40"></th></tr></thead>
                    @forelse($brands as $b)
                        <tbody x-data="{ edit: false }">
                            <tr x-show="!edit">
                                <td class="font-medium text-slate-900">{{ $b->name }}</td>
                                <td>{{ $b->products_count }}</td>
                                <td>@if($b->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-gray">Hidden</span>@endif</td>
                                <td class="text-right">
                                    @if($canManage)
                                        <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="edit = true">Edit</button>
                                        <form method="POST" action="{{ route('catalog.destroy', [$tab, $b->id]) }}" class="inline" data-confirm="Delete the brand “{{ $b->name }}”?" data-confirm-label="Delete" data-danger="1">
                                            @csrf @method('DELETE')<button class="btn btn-secondary btn-sm text-red-600">Delete</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                            @if($canManage)
                                <tr x-show="edit" x-cloak class="bg-slate-50"><td colspan="4">
                                    <form method="POST" action="{{ route('catalog.update', [$tab, $b->id]) }}" class="grid gap-3 sm:grid-cols-4">
                                        @csrf @method('PUT')
                                        <x-form.input name="name" label="Name" :value="$b->name" required :field="'name'" class="sm:col-span-2" />
                                        <x-form.checkbox name="is_active" label="Active" :checked="$b->is_active" :field="'is_active'" />
                                        <div class="flex items-end gap-2"><button class="btn btn-primary btn-sm">Save</button><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="edit = false">Cancel</button></div>
                                    </form>
                                </td></tr>
                            @endif
                        </tbody>
                    @empty
                        <tbody><tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No brands yet.</td></tr></tbody>
                    @endforelse
                </table>
            @else
                <table class="table">
                    <thead><tr><th>Unit</th><th>Short</th><th>Decimals</th><th class="w-24">Products</th><th class="w-40"></th></tr></thead>
                    @foreach($units as $u)
                        <tbody x-data="{ edit: false }">
                            <tr x-show="!edit">
                                <td class="font-medium text-slate-900">{{ $u->name }}</td>
                                <td>{{ $u->short_name }}</td>
                                <td>{{ $u->allows_decimal ? 'Yes' : 'Whole numbers only' }}</td>
                                <td>{{ $u->products_count }}</td>
                                <td class="text-right">
                                    @if($canManage)
                                        <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="edit = true">Edit</button>
                                        <form method="POST" action="{{ route('catalog.destroy', [$tab, $u->id]) }}" class="inline" data-confirm="Delete the unit “{{ $u->name }}”?" data-confirm-label="Delete" data-danger="1">
                                            @csrf @method('DELETE')<button class="btn btn-secondary btn-sm text-red-600">Delete</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                            @if($canManage)
                                <tr x-show="edit" x-cloak class="bg-slate-50"><td colspan="5">
                                    <form method="POST" action="{{ route('catalog.update', [$tab, $u->id]) }}" class="grid gap-3 sm:grid-cols-5">
                                        @csrf @method('PUT')
                                        <x-form.input name="name" label="Name" :value="$u->name" required :field="'name'" class="sm:col-span-2" />
                                        <x-form.input name="short_name" label="Short" :value="$u->short_name" required :field="'short_name'" />
                                        <x-form.checkbox name="allows_decimal" label="Allows decimals" :checked="$u->allows_decimal" :field="'allows_decimal'" />
                                        <div class="flex items-end gap-2"><button class="btn btn-primary btn-sm">Save</button><button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="edit = false">Cancel</button></div>
                                    </form>
                                </td></tr>
                            @endif
                        </tbody>
                    @endforeach
                </table>
            @endif
        </div>
        <p class="help mt-3">Anything used by a product cannot be deleted; untick <em>Active</em> to hide it instead. Categories go two levels deep (a main category and its subcategories).</p>
    </div>
</x-layouts.app>
