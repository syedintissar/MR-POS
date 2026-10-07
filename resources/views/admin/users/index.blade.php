<x-layouts.app title="Users">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex gap-2">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, username, email" class="input w-64">
            <button class="btn btn-secondary" data-no-loading>Search</button>
        </form>
        @can('users.manage')
            <a href="{{ route('users.create') }}" class="btn btn-primary">+ New user</a>
        @endcan
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>
            @forelse($users as $u)
                <tr>
                    <td class="font-medium text-slate-800">{{ $u->name }}@if($u->is(auth()->user())) <span class="badge badge-blue ml-1">you</span>@endif</td>
                    <td>{{ $u->username }}</td>
                    <td>{{ $u->roleName() ?: '—' }}</td>
                    <td>
                        @if($u->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-gray">Disabled</span>@endif
                        @if($u->must_change_password)<span class="badge badge-amber ml-1" title="Must choose a new password at next login">Password change due</span>@endif
                    </td>
                    <td class="text-slate-500">{{ $u->last_login_at?->format('d M Y, H:i') ?? 'Never' }}</td>
                    <td class="text-right">@can('users.manage')<a href="{{ route('users.edit', $u) }}" class="btn btn-secondary btn-sm">Edit</a>@endcan</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No users match your search.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>
</x-layouts.app>
