<x-layouts.app title="Reports">
    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">Reports</h2>
            <p class="mt-1 text-sm text-slate-500">Choose a report, pick the dates, and look at it on screen, or print it. @if($canExport) You can also save it as PDF, Excel or CSV. @endif You see the reports your role allows.</p>
        </div>

        @foreach($groups as $group => $reports)
            <section class="space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group }}</h3>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($reports as $r)
                        <a href="{{ route('reports.show', $r['key']) }}" class="card card-body group block transition duration-200 hover:-translate-y-0.5 hover:shadow-md" data-no-loading>
                            <div class="font-semibold text-slate-900 group-hover:text-brand-700">{{ $r['title'] }}</div>
                            <p class="mt-1 text-sm text-slate-500">{{ $r['description'] }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <section class="space-y-3">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Also in the menu</h3>
            <div class="flex flex-wrap gap-2 text-sm">
                @can('inventory.view')<a href="{{ route('inventory.stock') }}" class="btn btn-secondary btn-sm">Stock levels and low stock</a><a href="{{ route('inventory.movements') }}" class="btn btn-secondary btn-sm">Stock card (every movement)</a>@endcan
                @can('reports.customers')<a href="{{ route('receivables.index') }}" class="btn btn-secondary btn-sm">Who owes us (with invoices)</a>@endcan
                @can('audit_logs.view')<a href="{{ route('audit.index') }}" class="btn btn-secondary btn-sm">Audit log</a>@endcan
            </div>
        </section>
    </div>
</x-layouts.app>
