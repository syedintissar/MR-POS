<x-layouts.app title="Roles & permissions">
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-slate-600">Roles decide what each person can see and do. Cashiers never see cost or profit unless you give them that permission.</p>
        <a href="{{ route('roles.create') }}" class="btn btn-primary">+ New role</a>
    </div>

    @error('role')<div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>@enderror

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Role</th><th>Users</th><th>Permissions</th><th></th></tr></thead>
            <tbody>
            @foreach($roles as $r)
                <tr>
                    <td class="font-medium text-slate-800">{{ $r->name }}
                        @if(in_array($r->name, $defaultNames, true))<span class="badge badge-gray ml-1">built-in</span>@endif
                    </td>
                    <td>{{ $r->users_count }}</td>
                    <td>{{ $r->name === 'Admin' ? 'All (always)' : $r->permissions_count }}</td>
                    <td class="flex justify-end gap-2">
                        <a href="{{ route('roles.edit', $r) }}" class="btn btn-secondary btn-sm">{{ $r->name === 'Admin' ? 'View' : 'Edit' }}</a>
                        @unless(in_array($r->name, $defaultNames, true))
                            <form method="POST" action="{{ route('roles.destroy', $r) }}" data-confirm="Delete the role {{ $r->name }}?" data-confirm-label="Delete" data-danger="1">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
