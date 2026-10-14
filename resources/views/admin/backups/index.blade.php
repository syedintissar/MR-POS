@php
    $user = auth()->user();
    $badge = fn ($b) => match (true) {
        $b->pruned_at !== null => ['gray', 'Removed'],
        $b->status === 'success' => ['green', 'OK'],
        $b->status === 'running' => ['amber', 'Running'],
        default => ['red', 'FAILED'],
    };
@endphp
<x-layouts.app title="Backups">
    <div class="space-y-6">

        {{-- Health --}}
        @if(count($warnings))
            <div class="space-y-2">
                @foreach($warnings as $w)
                    <div class="flex items-start gap-3 rounded-md border px-4 py-3 text-sm {{ $w['level'] === 'critical' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
                        <span class="font-bold">{{ $w['level'] === 'critical' ? '⚠' : '!' }}</span><span>{{ $w['message'] }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">Backups are healthy: recent, verified, and copied to a second location.</div>
        @endif

        {{-- Actions --}}
        <div class="card card-body flex flex-wrap items-center gap-3">
            @can('backups.create')
                <form method="POST" action="{{ route('backups.store') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary" data-loading-text="Backing up… please wait">Backup Now</button>
                </form>
                <form method="POST" action="{{ route('backups.retry-transfers') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Retry pending copies</button>
                </form>
            @endcan
            @can('backups.settings')<a href="{{ route('backups.recovery-kit') }}" target="_blank" class="btn btn-secondary" data-no-loading>Recovery Kit (print)</a>@endcan
            @can('backups.create')
                <details class="w-full"><summary class="cursor-pointer text-sm text-slate-600">Bring a backup file from a USB drive or another PC</summary>
                    <form method="POST" action="{{ route('backups.upload') }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap items-end gap-3">@csrf
                        <div><label class="label" for="file">Backup file (.zip)</label><input id="file" type="file" name="file" accept=".zip" required class="input"></div>
                        <div><label class="label" for="backup_password">Its password (only if it was encrypted)</label><input id="backup_password" type="password" name="backup_password" class="input" autocomplete="off"></div>
                        <button class="btn btn-secondary" data-loading-text="Checking…">Check and keep</button>
                    </form>
                </details>
            @endcan
            <div class="text-xs text-slate-500">Stored in <code class="rounded bg-slate-100 px-1">{{ $root }}</code>. Selling is not interrupted while a backup runs.</div>
        </div>

        {{-- History --}}
        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Backup history</h2></div>
            <table class="table">
                <thead><tr><th>When</th><th>Type</th><th>Status</th><th>Verified</th><th>Size</th><th>Copies</th><th>By</th><th></th></tr></thead>
                <tbody>
                @forelse($backups as $b)
                    @php([$color, $text] = $badge($b))
                    <tr>
                        <td class="whitespace-nowrap">{{ $b->started_at->format('d M Y, H:i') }}</td>
                        <td>{{ ucfirst($b->tier) }}@if($b->encrypted) <span class="badge badge-blue" title="AES-256 encrypted">🔒</span>@endif @if($b->is_protected)<span class="badge badge-amber">protected</span>@endif</td>
                        <td>
                            <span class="badge badge-{{ $color }}">{{ $text }}</span>
                            @if($b->status === 'failed')<div class="mt-1 max-w-xs text-xs text-red-700">{{ \Illuminate\Support\Str::limit($b->error_message, 160) }}</div>@endif
                        </td>
                        <td>
                            @if($b->status === 'success' && ! $b->pruned_at)
                                @if($b->verify_status === 'passed')<span class="badge badge-green" title="{{ $b->verify_notes }}">{{ ucfirst($b->verify_level) }} ✓</span>
                                @elseif($b->verify_status === 'failed')<span class="badge badge-red" title="{{ $b->verify_notes }}">Failed</span>
                                @else<span class="badge badge-gray">Not yet</span>@endif
                            @else — @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $b->status === 'success' ? number_format($b->size_bytes / 1048576, 1).' MB' : '—' }}</td>
                        <td>
                            @forelse($b->transfers as $t)
                                <div class="text-xs"><span class="badge badge-{{ $t->status === 'success' ? 'green' : ($t->status === 'failed' ? 'red' : 'amber') }}" title="{{ $t->error_message }}">{{ $t->destination?->name }}: {{ $t->status }}</span></div>
                            @empty <span class="text-xs text-slate-400">this PC only</span> @endforelse
                        </td>
                        <td class="text-xs text-slate-500">{{ $b->triggeredBy?->username ?? 'automatic' }}</td>
                        <td class="whitespace-nowrap text-right">
                            @if($b->status === 'success' && ! $b->pruned_at)
                                <div class="flex justify-end gap-1.5">
                                    @can('backups.verify')
                                        <form method="POST" action="{{ route('backups.verify', $b) }}">@csrf <input type="hidden" name="level" value="deep">
                                            <button class="btn btn-secondary btn-sm" title="Imports the backup into a scratch database to prove it can be restored" data-loading-text="Testing…">Test restore</button></form>
                                    @endcan
                                    @can('backups.download')<a href="{{ route('backups.download', $b) }}" class="btn btn-secondary btn-sm">Download</a>@endcan
                                    @can('backups.settings')
                                        <form method="POST" action="{{ route('backups.protect', $b) }}">@csrf <button class="btn btn-secondary btn-sm" data-no-loading>{{ $b->is_protected ? 'Unprotect' : 'Protect' }}</button></form>
                                    @endcan
                                    @can('backups.delete')
                                        @unless($b->is_protected)
                                            <form method="POST" action="{{ route('backups.destroy', $b) }}" data-confirm="Delete this backup file permanently?" data-confirm-label="Delete" data-danger="1">@csrf @method('DELETE')
                                                <button class="btn btn-secondary btn-sm text-red-600">Delete</button></form>
                                        @endunless
                                    @endcan
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No backups yet. Click <strong>Backup Now</strong> to make the first one.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Destinations --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">Second copy (USB drive / another PC)</h2></div>
                <div class="card-body space-y-4">
                    <p class="text-sm text-slate-600">A backup kept only on this computer is lost if its disk fails or the PC is stolen. Every new backup is copied to each destination below. If a destination is unplugged, the copy waits and is retried automatically; selling is never affected.</p>
                    @forelse($destinations as $d)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-3">
                            <div>
                                <div class="text-sm font-medium text-slate-800">{{ $d->name }} @unless($d->is_active)<span class="badge badge-gray">off</span>@endunless</div>
                                <div class="text-xs text-slate-500">{{ $d->config['path'] ?? '' }}</div>
                                @if($d->last_error)<div class="text-xs text-red-700">{{ \Illuminate\Support\Str::limit($d->last_error, 140) }}</div>
                                @elseif($d->last_success_at)<div class="text-xs text-emerald-700">Last copy: {{ $d->last_success_at->diffForHumans() }}</div>@endif
                            </div>
                            @can('backups.settings')
                                <div class="flex gap-1.5">
                                    <form method="POST" action="{{ route('backups.destinations.test', $d) }}">@csrf <button class="btn btn-secondary btn-sm" data-no-loading>Test</button></form>
                                    <form method="POST" action="{{ route('backups.destinations.toggle', $d) }}">@csrf <button class="btn btn-secondary btn-sm" data-no-loading>{{ $d->is_active ? 'Turn off' : 'Turn on' }}</button></form>
                                </div>
                            @endcan
                        </div>
                    @empty
                        <div class="rounded-md border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">No second copy set up yet.</div>
                    @endforelse

                    @can('backups.settings')
                        <form method="POST" action="{{ route('backups.destinations.store') }}" class="space-y-3 border-t border-slate-200 pt-4">
                            @csrf
                            <h3 class="text-sm font-semibold text-slate-700">Add a destination</h3>
                            <x-form.input name="name" label="Name" placeholder="e.g. USB drive F:" required />
                            <x-form.input name="path" label="Folder" placeholder="F:\MRPOS-Backups  or  \\COUNTER2\Backups" required
                                          help="Cloud storage (Google Drive, S3, SFTP) can be added later; the backup system is built to support it." />
                            <x-form.checkbox name="requires_encryption" label="Only copy encrypted backups here" :checked="false" />
                            <button type="submit" class="btn btn-primary">Add destination</button>
                        </form>
                    @endcan
                </div>
            </div>

            {{-- Settings --}}
            @can('backups.settings')
                <form method="POST" action="{{ route('backups.settings') }}" class="card">
                    @csrf @method('PUT')
                    <div class="card-header"><h2 class="card-title">Schedule, retention &amp; encryption</h2></div>
                    <div class="card-body grid gap-4 sm:grid-cols-2">
                        @foreach($fields as $key => $def)
                            @if($def['type'] === 'bool')
                                <x-form.checkbox :name="$key" :label="$def['label']" :checked="$values[$key]" class="sm:col-span-2" />
                            @elseif($def['type'] === 'select')
                                <x-form.select :name="$key" :label="$def['label']" :options="$def['options']" :value="$values[$key]" />
                            @elseif($def['type'] === 'encrypted')
                                <x-form.input :name="$key" :label="$def['label']" type="password" autocomplete="new-password"
                                              :help="$encryptionPasswordSet ? 'A password is set. Leave blank to keep it.' : 'Write this password down and keep it away from the shop. Without it, encrypted backups cannot be opened.'" />
                            @elseif($def['type'] === 'time')
                                <x-form.input :name="$key" :label="$def['label']" type="time" :value="$values[$key]" />
                            @else
                                <x-form.input :name="$key" :label="$def['label']" type="number" :value="$values[$key]" />
                            @endif
                        @endforeach
                    </div>
                    <div class="flex justify-end border-t border-slate-200 px-5 py-3">
                        <button type="submit" class="btn btn-primary">Save backup settings</button>
                    </div>
                </form>
            @endcan
        </div>

        @can('backups.restore')
            <div class="card card-body text-sm text-slate-600">
                <h2 class="card-title mb-1">Restoring a backup</h2>
                Restoring replaces <strong>all</strong> current data, so it is done from the server PC, not from this page. Open a terminal in the application folder and run
                <code class="mt-1 block rounded bg-slate-100 p-2 text-xs">php artisan pos:backup:list<br>php artisan pos:backup:restore --id=&lt;number&gt;</code>
                You will be asked for an administrator login and a confirmation. The backup is tested first, and a safety copy of the current data is made automatically. See the Backup &amp; Restore guide.
            </div>
        @endcan
    </div>
</x-layouts.app>
