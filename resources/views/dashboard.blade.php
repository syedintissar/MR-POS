@php
    use App\Services\Reports\DashboardService as Dash;
    use App\Support\Money;
@endphp
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

    @if($glance)
        @php
            $f = $glance['filters']; $now = $glance['now']; $before = $glance['before']; $withProfit = $glance['withProfit']; $withFinancials = $glance['withFinancials'];
            $chip = function (string $a, string $b) {
                $d = Dash::change($a, $b);
                if ($d === null) { return '<span class="text-slate-400">no earlier figure to compare</span>'; }
                $up = (float) $d >= 0;

                return '<span class="'.($up ? 'text-emerald-600' : 'text-red-600').'">'.($up ? '▲ ' : '▼ ').abs((float) $d).'%</span> <span class="text-slate-400">vs the period before</span>';
            };
        @endphp
        <section class="mb-6 space-y-4" aria-label="Business at a glance">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-800">Business at a glance <span class="font-normal text-slate-500">· {{ $f->label() }}</span></h3>
                <form method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-center gap-1.5" x-data="{ custom: {{ $f->preset === 'custom' ? 'true' : 'false' }} }">
                    @foreach($glance['presets'] as $k => $label)
                        @if($k === 'custom')
                            <button type="button" class="rounded-full border px-3 py-1 text-xs font-medium transition {{ $f->preset === 'custom' ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-700' }}" data-no-loading @click="custom = !custom">{{ $label }}</button>
                        @else
                            <a href="{{ route('dashboard', ['preset' => $k]) }}" class="rounded-full border px-3 py-1 text-xs font-medium transition {{ $f->preset === $k ? 'border-brand-600 bg-brand-600 text-white hover:text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-700' }}" data-no-loading>{{ $label }}</a>
                        @endif
                    @endforeach
                    <span x-show="custom" x-cloak class="flex items-center gap-1.5">
                        <input type="hidden" name="preset" value="custom">
                        <input type="date" name="from" value="{{ $f->from }}" class="input w-36 py-1 text-xs" aria-label="From">
                        <input type="date" name="to" value="{{ $f->to }}" class="input w-36 py-1 text-xs" aria-label="To">
                        <button class="btn btn-primary btn-sm" data-no-loading>Show</button>
                    </span>
                </form>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="card card-body">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invoiced</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($now['total']) }}</div>
                    <div class="text-xs text-slate-500">{{ $now['invoices'] }} invoice(s) · average {{ Money::format($now['average']) }}</div>
                    <div class="mt-1 text-xs">{!! $chip($now['total'], $before['total']) !!}</div>
                </div>
                <div class="card card-body">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">After returns</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($now['net']) }}</div>
                    <div class="text-xs text-slate-500">{{ Money::format($now['returns']) }} given back @if((float) $now['discounts']) · {{ Money::format($now['discounts']) }} in discounts @endif</div>
                    <div class="mt-1 text-xs">{!! $chip($now['net'], $before['net']) !!}</div>
                </div>
                <div class="card card-body">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service jobs delivered</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($now['job_value']) }}</div>
                    <div class="text-xs text-slate-500">{{ $now['jobs'] }} job(s) handed over</div>
                    <div class="mt-1 text-xs">{!! $chip($now['job_value'], $before['job_value']) !!}</div>
                </div>
                @if($withFinancials)
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Money received</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($now['received']) }}</div>
                        <div class="text-xs text-slate-500">of which cash {{ Money::format($now['received_cash']) }}</div>
                        <div class="mt-1 text-xs">{!! $chip($now['received'], $before['received']) !!}</div>
                    </div>
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Left on account</div>
                        <div class="mt-1 text-2xl font-semibold {{ (float) $now['on_account'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ Money::withSymbol($now['on_account']) }}</div>
                        <div class="text-xs text-slate-500">still unpaid on this period's invoices</div>
                    </div>
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Purchases</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($now['purchases']) }}</div>
                        <div class="text-xs text-slate-500">{{ $now['purchase_count'] }} bill(s) received</div>
                        <div class="mt-1 text-xs">{!! $chip($now['purchases'], $before['purchases']) !!}</div>
                    </div>
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Expenses</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($now['expenses']) }}</div>
                        <div class="mt-1 text-xs">{!! $chip($now['expenses'], $before['expenses']) !!}</div>
                    </div>
                @endif
                @if($withProfit)
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gross profit</div>
                        <div class="mt-1 text-2xl font-semibold {{ (float) $now['gross_profit'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ Money::withSymbol($now['gross_profit']) }}</div>
                        <div class="text-xs text-slate-500">{{ $now['margin'] }}% of revenue · service {{ Money::format($now['service_profit']) }}</div>
                        <div class="mt-1 text-xs">{!! $chip($now['gross_profit'], $before['gross_profit']) !!}</div>
                    </div>
                    <div class="card card-body">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Net profit</div>
                        <div class="mt-1 text-2xl font-semibold {{ (float) $now['net_profit'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ Money::withSymbol($now['net_profit']) }}</div>
                        <div class="text-xs text-slate-500">after expenses and stock losses</div>
                        <div class="mt-1 text-xs"><a href="{{ route('reports.show', ['report' => 'profit', 'preset' => 'custom', 'from' => $f->from, 'to' => $f->to]) }}">Open the profit report →</a></div>
                    </div>
                @endif
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="card card-body">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Sales {{ $withProfit ? 'and gross profit ' : '' }}by {{ $glance['trend']['group'] }}</div>
                    <x-chart.bars :labels="$glance['trend']['labels']" :title="'Sales by '.$glance['trend']['group']"
                        :series="array_values(array_filter([
                            ['name' => 'Invoiced, after returns', 'values' => $glance['trend']['sales'], 'color' => '#c0121b'],
                            $withProfit ? ['name' => 'Gross profit', 'values' => $glance['trend']['profit'], 'color' => '#059669'] : null,
                        ]))"/>
                </div>
                <div class="card card-body">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Last 12 months compared with the year before</div>
                    <x-chart.bars :labels="$glance['year']['labels']" title="Sales over the last 12 months"
                        :series="[['name' => 'Last 12 months', 'values' => $glance['year']['this_year'], 'color' => '#c0121b'], ['name' => 'Same months a year earlier', 'values' => $glance['year']['last_year'], 'color' => '#cbd5e1']]"/>
                </div>
            </div>

            @if($glance['top'])
                <div class="card card-body">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Best sellers in this period</div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($glance['top'] as $t)
                            <li class="flex items-center justify-between gap-3 py-1.5"><a href="{{ route('products.show', $t['id']) }}" class="truncate text-slate-800">{{ $t['name'] }}</a>
                                <span class="shrink-0 text-slate-500">{{ $t['qty'] }} sold · <span class="font-medium text-slate-800">{{ Money::format($t['revenue']) }}</span></span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    @endif

    @if(! empty($latest))
        <section class="mb-6 space-y-3" aria-label="Latest activity">
            <h3 class="text-sm font-semibold text-slate-800">Latest activity</h3>
            <div class="grid gap-4 lg:grid-cols-2">
                @isset($latest['sales'])
                    <div class="card card-body">
                        <div class="flex items-center justify-between"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent sales</div><a href="{{ route('sales.index') }}" class="text-xs">See all</a></div>
                        <ul class="mt-2 divide-y divide-slate-100 text-xs">
                            @forelse($latest['sales'] as $s)
                                <li class="flex items-center justify-between gap-2 py-1"><span class="min-w-0 truncate"><a href="{{ route('sales.show', $s) }}" class="font-mono font-medium">{{ $s->invoice_no }}</a> <span class="text-slate-500">{{ $s->customer->is_walk_in ? 'walk-in' : $s->customer->name }} · {{ $s->sale_date->format('d M H:i') }}</span></span><span class="shrink-0 font-medium text-slate-800">{{ Money::format($s->grand_total) }}</span></li>
                            @empty
                                <li class="py-3 text-slate-500">No sales yet.</li>
                            @endforelse
                        </ul>
                    </div>
                @endisset
                @isset($latest['payments'])
                    <div class="card card-body">
                        <div class="flex items-center justify-between"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent payments received</div><a href="{{ route('customer-payments.index') }}" class="text-xs">See all</a></div>
                        <ul class="mt-2 divide-y divide-slate-100 text-xs">
                            @forelse($latest['payments'] as $p)
                                <li class="flex items-center justify-between gap-2 py-1"><span class="min-w-0 truncate"><a href="{{ route('customer-payments.show', $p) }}" class="font-mono font-medium">{{ $p->receipt_no }}</a> <span class="text-slate-500">{{ $p->customer->is_walk_in ? 'walk-in' : $p->customer->name }} · {{ $p->method->name }}</span></span><span class="shrink-0 font-medium text-slate-800">{{ Money::format($p->amount) }}</span></li>
                            @empty
                                <li class="py-3 text-slate-500">No payments yet.</li>
                            @endforelse
                        </ul>
                    </div>
                @endisset
                @isset($latest['expenses'])
                    <div class="card card-body">
                        <div class="flex items-center justify-between"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent expenses</div><a href="{{ route('expenses.index') }}" class="text-xs">See all</a></div>
                        <ul class="mt-2 divide-y divide-slate-100 text-xs">
                            @forelse($latest['expenses'] as $e)
                                <li class="flex items-center justify-between gap-2 py-1"><span class="min-w-0 truncate"><a href="{{ route('expenses.show', $e) }}" class="font-mono font-medium">{{ $e->expense_no }}</a> <span class="text-slate-500">{{ $e->category->name }} · {{ $e->expense_date->format('d M') }}</span></span><span class="shrink-0 font-medium text-slate-800">{{ Money::format($e->amount) }}</span></li>
                            @empty
                                <li class="py-3 text-slate-500">No expenses yet.</li>
                            @endforelse
                        </ul>
                    </div>
                @endisset
                @isset($latest['jobs'])
                    <div class="card card-body">
                        <div class="flex items-center justify-between"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent service jobs</div><a href="{{ route('service.jobs.index') }}" class="text-xs">See all</a></div>
                        <ul class="mt-2 divide-y divide-slate-100 text-xs">
                            @forelse($latest['jobs'] as $j)
                                <li class="flex items-center justify-between gap-2 py-1"><span class="min-w-0 truncate"><a href="{{ route('service.jobs.show', $j) }}" class="font-mono font-medium">{{ $j->job_no }}</a> <span class="text-slate-500">{{ $j->customer->is_walk_in ? ($j->contact_name ?: 'walk-in') : $j->customer->name }}</span></span><span class="shrink-0 text-slate-600">{{ $j->statusLabel() }}</span></li>
                            @empty
                                <li class="py-3 text-slate-500">No jobs yet.</li>
                            @endforelse
                        </ul>
                    </div>
                @endisset
            </div>
        </section>
    @endif
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
        @if($service)
            <div class="card card-body">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $service['own'] ? 'My service jobs' : 'Service jobs' }}</div>
                    <a href="{{ route('service.jobs.index') }}" class="text-xs">See all</a>
                </div>
                <div class="mt-1 text-2xl font-semibold text-slate-900">{{ $service['open'] }} <span class="text-sm font-normal text-slate-500">open</span></div>
                <div class="text-xs text-slate-500">
                    <a href="{{ route('service.jobs.index', ['view' => 'ready']) }}">{{ $service['ready'] }} ready to collect</a> ·
                    <a href="{{ route('service.jobs.index', ['view' => 'overdue']) }}" class="{{ $service['overdue'] ? 'font-semibold text-red-600' : '' }}">{{ $service['overdue'] }} overdue</a>
                </div>
            </div>
        @endif
        @if($myJobs && $myJobs->isNotEmpty())
            <div class="card card-body sm:col-span-2">
                <div class="flex items-center justify-between"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">My open jobs</div><a href="{{ route('service.jobs.index') }}" class="text-xs">See all</a></div>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($myJobs as $job)
                        <li class="flex items-center justify-between gap-3 py-1.5">
                            <span class="min-w-0"><a href="{{ route('service.jobs.show', $job) }}" class="font-mono text-xs font-medium">{{ $job->job_no }}</a> <span class="truncate text-slate-700">{{ $job->customer->is_walk_in ? ($job->contact_name ?: 'Walk-in') : $job->customer->name }}</span></span>
                            <span class="shrink-0 text-xs {{ $job->expected_delivery_at && $job->expected_delivery_at->isPast() ? 'font-semibold text-red-600' : 'text-slate-500' }}">{{ $job->statusLabel() }}@if($job->expected_delivery_at) · due {{ $job->expected_delivery_at->format('d M') }}@endif</span>
                        </li>
                    @endforeach
                </ul>
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
    </div>
</x-layouts.app>
