<x-layouts.app title="Audit log">
    <form method="GET" class="card card-body mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div><label class="label">From</label><input type="date" name="from" value="{{ request('from') }}" class="input"></div>
        <div><label class="label">To</label><input type="date" name="to" value="{{ request('to') }}" class="input"></div>
        <div><label class="label">User</label>
            <select name="user_id" class="input"><option value="">Anyone</option>
                @foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
        <div><label class="label">Area</label>
            <select name="module" class="input"><option value="">All</option>
                @foreach($modules as $m)<option @selected(request('module') === $m)>{{ $m }}</option>@endforeach</select></div>
        <div><label class="label">Action</label>
            <select name="event" class="input"><option value="">All</option>
                @foreach($events as $e)<option @selected(request('event') === $e)>{{ $e }}</option>@endforeach</select></div>
        <div><label class="label">Search</label><input type="search" name="q" value="{{ request('q') }}" class="input" placeholder="text"></div>
        <div class="flex items-end gap-2 sm:col-span-3 lg:col-span-6">
            <button class="btn btn-primary" data-no-loading>Filter</button>
            <a href="{{ route('audit.index') }}" class="btn btn-secondary">Clear</a>
            <span class="ml-auto"></span>
        </div>
    </form>

    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-slate-500">Entries cannot be edited or deleted. Each is chained to the one before it, so tampering is detectable.</p>
        <form method="POST" action="{{ route('audit.verify') }}">@csrf <button class="btn btn-secondary">Verify integrity</button></form>
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>When</th><th>User</th><th>Area</th><th>Action</th><th>Details</th><th>From</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr x-data="{ open: false }" class="align-top">
                    <td class="whitespace-nowrap text-slate-600">{{ $log->created_at->format('d M Y, H:i:s') }}</td>
                    <td>{{ $log->user?->name ?? 'system / not logged in' }}</td>
                    <td>{{ $log->module }}</td>
                    <td><span class="badge {{ str_contains($log->event, 'fail') || str_contains($log->event, 'void') || str_contains($log->event, 'delete') ? 'badge-red' : 'badge-gray' }}">{{ $log->event }}</span></td>
                    <td class="max-w-md">
                        {{ $log->description }}
                        @if($log->old_values || $log->new_values)
                            <button type="button" class="ml-1 text-xs text-brand-600 hover:underline" data-no-loading @click="open = !open" x-text="open ? 'hide' : 'changes'"></button>
                            <div x-show="open" x-cloak class="mt-1 rounded bg-slate-50 p-2 text-xs">
                                @if($log->old_values)<div><strong>Before:</strong> <code class="break-all">{{ json_encode($log->old_values, JSON_UNESCAPED_UNICODE) }}</code></div>@endif
                                @if($log->new_values)<div><strong>After:</strong> <code class="break-all">{{ json_encode($log->new_values, JSON_UNESCAPED_UNICODE) }}</code></div>@endif
                            </div>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-xs text-slate-500">{{ $log->ip_address ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No audit entries match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
</x-layouts.app>
