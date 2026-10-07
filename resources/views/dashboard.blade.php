<x-layouts.app title="Dashboard">
    @if(count($warnings))
        <div class="mb-6 space-y-2">
            @foreach($warnings as $w)
                <div class="flex items-start gap-3 rounded-md border px-4 py-3 text-sm {{ $w['level'] === 'critical' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
                    <span class="mt-0.5 font-bold">{{ $w['level'] === 'critical' ? '⚠' : '!' }}</span>
                    <span class="flex-1">{{ $w['message'] }}</span>
                </div>
            @endforeach
            @can('backups.view')
                <a href="{{ route('backups.index') }}" class="inline-block text-sm font-medium">Open Backups →</a>
            @endcan
        </div>
    @endif

    <div class="mb-6">
        <h2 class="text-xl font-semibold text-slate-800">Welcome, {{ auth()->user()->name }}</h2>
        <p class="text-sm text-slate-500">{{ $shopName }} · signed in as {{ auth()->user()->roleName() }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @can('backups.view')
            <div class="card card-body">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Last good backup</div>
                @if($lastBackup)
                    <div class="mt-1 text-lg font-semibold text-slate-800">{{ $lastBackup->started_at->diffForHumans() }}</div>
                    <div class="text-xs text-slate-500">{{ $lastBackup->started_at->format('d M Y, H:i') }} · {{ ucfirst($lastBackup->tier) }} · {{ number_format($lastBackup->size_bytes / 1048576, 1) }} MB</div>
                @else
                    <div class="mt-1 text-lg font-semibold text-red-600">Never</div>
                    <div class="text-xs text-slate-500">Open Backups and run "Backup Now".</div>
                @endif
            </div>
        @endcan

        <div class="card card-body">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">System</div>
            <div class="mt-1 text-sm text-slate-700">Database: <span class="font-medium">{{ $dbVersion ?? 'not reachable' }}</span></div>
            <div class="text-sm text-slate-700">Works without internet: <span class="font-medium text-emerald-700">yes</span></div>
        </div>

        @if(count($systemChecks))
            @php($failing = collect($systemChecks)->where('status', '!=', 'ok'))
            <div class="card card-body sm:col-span-2 xl:col-span-3" x-data="{ open: {{ $failing->isNotEmpty() ? 'true' : 'false' }} }">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">System checks <span class="font-normal normal-case">(as seen by the web server)</span></div>
                        <div class="mt-1 text-sm {{ $failing->contains('status', 'fail') ? 'font-semibold text-red-700' : 'text-slate-700' }}">
                            @if($failing->isEmpty()) All {{ count($systemChecks) }} checks passed.
                            @else {{ $failing->count() }} item(s) need attention.
                            @endif
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="open = !open" x-text="open ? 'Hide' : 'Show all'"></button>
                </div>
                <table class="mt-3 w-full text-sm" x-show="open" x-cloak>
                    @foreach($systemChecks as $c)
                        <tr class="border-t border-slate-100">
                            <td class="py-1.5 pr-3 text-slate-700">{{ $c['name'] }}</td>
                            <td class="py-1.5 pr-3"><span class="badge {{ ['ok' => 'badge-green', 'warn' => 'badge-amber', 'fail' => 'badge-red'][$c['status']] }}">{{ strtoupper($c['status']) }}</span></td>
                            <td class="py-1.5 text-xs text-slate-500 break-all">{{ $c['detail'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        <div class="card card-body xl:col-span-1">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Coming next</div>
            <p class="mt-1 text-sm text-slate-600">Products &amp; stock, purchases, POS, customers, repair jobs and reports are built in the next phases. Sales and profit figures will appear here.</p>
        </div>
    </div>
</x-layouts.app>
