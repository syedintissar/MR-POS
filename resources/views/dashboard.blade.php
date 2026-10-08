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

    @if($integrity)
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <div class="font-semibold">⚠ Records do not add up ({{ $integrity['total'] }} problem(s), checked {{ \Illuminate\Support\Carbon::parse($integrity['ran_at'])->diffForHumans() }})</div>
            <ul class="mt-1 list-disc pl-5">@foreach(array_slice($integrity['problems'], 0, 3) as $p)<li>{{ $p }}</li>@endforeach</ul>
            <p class="mt-1 text-xs">Nothing was changed automatically. Please ask your computer person to look at it; details are in the audit log.</p>
        </div>
    @endif
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-slate-800">Welcome, {{ auth()->user()->name }}</h2>
        <p class="text-sm text-slate-500">{{ $shopName }} · signed in as {{ auth()->user()->roleName() }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @if($stockAlerts)
            <div class="card card-body sm:col-span-2 xl:col-span-1">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Stock alerts</div>
                    <a href="{{ route('inventory.stock', ['level' => 'low']) }}" class="text-xs">See all</a>
                </div>
                <div class="mt-1 flex gap-6">
                    <a href="{{ route('inventory.stock', ['level' => 'out']) }}" class="block"><span class="text-2xl font-semibold {{ $stockAlerts['out'] ? 'text-red-600' : 'text-slate-900' }}">{{ $stockAlerts['out'] }}</span><span class="block text-xs text-slate-500">out of stock</span></a>
                    <a href="{{ route('inventory.stock', ['level' => 'low']) }}" class="block"><span class="text-2xl font-semibold {{ $stockAlerts['low'] ? 'text-amber-600' : 'text-slate-900' }}">{{ $stockAlerts['low'] }}</span><span class="block text-xs text-slate-500">running low</span></a>
                </div>
                @if($stockAlerts['items']->isNotEmpty())
                    <ul class="mt-3 divide-y divide-slate-100 text-sm">
                        @foreach($stockAlerts['items'] as $p)
                            <li class="flex items-center justify-between gap-2 py-1.5"><a href="{{ route('products.show', $p) }}" class="truncate text-slate-800">{{ $p->name }}</a>
                                <span class="shrink-0 {{ (float) $p->current_stock <= 0 ? 'font-semibold text-red-600' : 'text-amber-600' }}">{{ \App\Support\Decimal::trim($p->current_stock) }} {{ $p->unit->short_name }}</span></li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-sm text-slate-500">Nothing is running low.</p>
                @endif
            </div>
        @endif
        @if($salesToday)
            <div class="card card-body">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $salesToday['own'] ? 'Your sales today' : 'Sales today' }}</div>
                    <a href="{{ route('sales.index', ['from' => now()->toDateString(), 'to' => now()->toDateString()]) }}" class="text-xs">See all</a>
                </div>
                <div class="mt-1 text-2xl font-semibold text-slate-900">{{ \App\Support\Money::withSymbol($salesToday['total']) }}</div>
                <div class="text-xs text-slate-500">{{ $salesToday['count'] }} sale(s)@can('pos.access') · <a href="{{ route('pos.index') }}">open the till</a>@endcan</div>
            </div>
        @endif
        @if($payables)
            <div class="card card-body">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Owed to suppliers</div>
                    @can('purchases.view')<a href="{{ route('purchases.index', ['payment' => 'unpaid']) }}" class="text-xs">Unpaid bills</a>@endcan
                </div>
                <div class="mt-1 text-2xl font-semibold {{ (float) $payables['owed'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ \App\Support\Money::withSymbol($payables['owed']) }}</div>
                <div class="text-xs text-slate-500">{{ $payables['suppliers'] }} supplier(s) · {{ $payables['open_bills'] }} open bill(s)@if($payables['drafts']) · {{ $payables['drafts'] }} draft(s) not received yet @endif</div>
            </div>
        @endif
        @if($receivables)
            <div class="card card-body">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Owed by customers</div>
                    @can('reports.customers')<a href="{{ route('receivables.index') }}" class="text-xs">Who owes us</a>@endcan
                </div>
                <div class="mt-1 text-2xl font-semibold {{ (float) $receivables['owed'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ \App\Support\Money::withSymbol($receivables['owed']) }}</div>
                <div class="text-xs text-slate-500">{{ $receivables['customers'] }} customer(s) · {{ $receivables['open_invoices'] }} unpaid invoice(s)</div>
            </div>
        @endif
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
            <p class="mt-1 text-sm text-slate-600">Products, stock, suppliers, purchases, the till, sales and customers are ready. Repair and refill jobs, returns, expenses and reports follow in the next phases, and their figures will appear here.</p>
        </div>
    </div>
</x-layouts.app>
