@php
    $editing = $role->exists;
    $isDefault = $editing && in_array($role->name, array_keys(\App\Support\PermissionCatalogue::defaultRoles()), true);
    $costPerms = \App\Support\PermissionCatalogue::COST_PROFIT;
@endphp
<x-layouts.app :title="$editing ? 'Role: '.$role->name : 'New role'">
    <form method="POST" action="{{ $editing ? route('roles.update', $role) : route('roles.store') }}" class="mx-auto max-w-5xl space-y-5"
          x-data="{ toggle(card, on) { card.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = on) } }">
        @csrf
        @if($editing) @method('PUT') @endif

        @if($locked)
            <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">The Admin role always has every permission and cannot be changed.</div>
        @endif

        <div class="card card-body">
            @if($isDefault || $locked)
                <div class="text-sm text-slate-600">Role name: <span class="font-semibold text-slate-800">{{ $role->name }}</span> <span class="badge badge-gray ml-1">built-in</span></div>
            @else
                <x-form.input name="name" label="Role name" :value="$role->name" required class="max-w-sm" />
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach($modules as $module => $perms)
                <div class="card" x-data>
                    <div class="card-header">
                        <h3 class="card-title">{{ $module }}</h3>
                        @unless($locked)
                            <div class="flex gap-2 text-xs">
                                <button type="button" class="text-brand-600 hover:underline" data-no-loading @click="toggle($root, true)">All</button>
                                <button type="button" class="text-slate-500 hover:underline" data-no-loading @click="toggle($root, false)">None</button>
                            </div>
                        @endunless
                    </div>
                    <div class="space-y-2 p-4">
                        @foreach($perms as $name => $desc)
                            <label class="flex cursor-pointer items-start gap-2.5">
                                <input type="checkbox" name="permissions[]" value="{{ $name }}"
                                       @checked($locked || in_array($name, old('permissions', $granted), true)) @disabled($locked)
                                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                <span class="text-sm">
                                    <span class="font-medium text-slate-800">{{ $name }}</span>
                                    @if(in_array($name, $costPerms, true))<span class="badge badge-amber ml-1">cost / profit</span>@endif
                                    <span class="block text-xs text-slate-500">{{ $desc }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="sticky bottom-0 -mx-4 flex justify-end gap-2 border-t border-slate-200 bg-slate-100/95 px-4 py-3 sm:-mx-6 sm:px-6">
            <a href="{{ route('roles.index') }}" class="btn btn-secondary">{{ $locked ? 'Back' : 'Cancel' }}</a>
            @unless($locked)<button type="submit" class="btn btn-primary">Save role</button>@endunless
        </div>
    </form>
</x-layouts.app>
