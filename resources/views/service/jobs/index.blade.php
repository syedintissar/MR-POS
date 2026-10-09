@php
    use App\Models\ServiceJob;
    use App\Support\Money;

    $badge = fn (string $s) => match ($s) {
        ServiceJob::COMPLETED => 'badge-green', ServiceJob::DELIVERED => 'badge-gray', ServiceJob::CANCELLED => 'badge-red',
        ServiceJob::WAITING_APPROVAL, ServiceJob::WAITING_PARTS => 'badge-amber', ServiceJob::IN_PROGRESS, ServiceJob::APPROVED => 'badge-blue',
        default => 'badge-gray',
    };
@endphp
<x-layouts.app title="Service jobs">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="view" value="{{ $view }}">
            <div><label class="label" for="q">Find a job</label>
                <input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="JOB-000123, name, phone, serial, model" class="input w-72" autofocus></div>
            <div><label class="label" for="technician">Technician</label>
                <select id="technician" name="technician" class="input w-44"><option value="">Anyone</option><option value="mine" @selected(request('technician') === 'mine')>Mine</option><option value="none" @selected(request('technician') === 'none')>Not assigned</option>
                    @foreach($technicians as $t)<option value="{{ $t->id }}" @selected((string) request('technician') === (string) $t->id)>{{ $t->name }}</option>@endforeach</select></div>
            <div><label class="label" for="type">Service</label>
                <select id="type" name="type" class="input w-44"><option value="">All</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected((int) request('type') === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
            <div><label class="label" for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}" class="input w-36"></div>
            <div><label class="label" for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}" class="input w-36"></div>
            <button class="btn btn-secondary" data-no-loading>Find</button>
        </form>
        @can('service_jobs.create')<a href="{{ route('service.jobs.create') }}" class="btn btn-primary">Receive a device</a>@endcan
    </div>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach(['open' => ['Open', $counts['open']], 'ready' => ['Ready to collect', $counts['ready']], 'overdue' => ['Overdue', $counts['overdue']], 'delivered' => ['Delivered', null], 'cancelled' => ['Cancelled', null], 'all' => ['Everything', null]] as $key => [$label, $n])
            <a href="{{ route('service.jobs.index', array_merge(request()->except('view', 'page'), ['view' => $key])) }}"
               class="rounded-full border px-3 py-1 {{ $view === $key ? 'border-brand-600 bg-brand-600 text-white hover:text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">{{ $label }}@if($n !== null) <span class="{{ $view === $key ? 'text-white/80' : 'text-slate-400' }}">{{ $n }}</span>@endif</a>
        @endforeach
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Job</th><th>Received</th><th>Customer</th><th>Device</th><th>Service</th><th>Technician</th><th>Status</th><th>Promised</th>@if($seeMoney)<th class="text-right">Total</th>@endif<th></th></tr></thead>
            <tbody>
            @forelse($jobs as $j)
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs font-medium"><a href="{{ route('service.jobs.show', $j) }}">{{ $j->job_no }}</a>@if($j->priority === 'urgent') <span class="badge badge-red">urgent</span>@endif</td>
                    <td class="whitespace-nowrap">{{ $j->received_at->format('d M, H:i') }}</td>
                    <td>{{ $j->contact_name ?: $j->customer->name }}<div class="text-xs text-slate-500">{{ $j->contact_phone }}</div></td>
                    <td>{{ ServiceJob::DEVICE_TYPES[$j->device_type] ?? $j->device_type }}<div class="text-xs text-slate-500">{{ collect([$j->model, $j->serial_number ? 'S/N '.$j->serial_number : null])->filter()->implode(' · ') }}</div></td>
                    <td>{{ $j->serviceType->name }}</td>
                    <td>{{ $j->technician?->name ?? '—' }}</td>
                    <td><span class="badge {{ $badge($j->status) }}">{{ $j->statusLabel() }}</span></td>
                    <td class="whitespace-nowrap {{ $j->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ $j->expected_delivery_at?->format('d M') ?? '—' }}@if($j->isOverdue()) <span class="text-xs">overdue</span>@endif</td>
                    @if($seeMoney)<td class="text-right">{{ (float) $j->grand_total ? Money::format($j->grand_total) : '—' }}@if($j->status === ServiceJob::DELIVERED && (float) $j->due_amount > 0)<div class="text-xs text-amber-700">{{ Money::format($j->due_amount) }} due</div>@endif</td>@endif
                    <td class="text-right"><a href="{{ route('service.jobs.show', $j) }}" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="{{ $seeMoney ? 10 : 9 }}" class="px-4 py-12 text-center text-slate-500">No jobs found. @can('service_jobs.create')<a href="{{ route('service.jobs.create') }}">Receive a device</a> to start one.@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $jobs->links() }}</div>
</x-layouts.app>
