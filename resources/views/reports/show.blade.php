@php
    use App\Services\Reports\Periods;
    use App\Services\Reports\ReportFilters;
    use App\Services\Reports\ReportResult;

    $uses = fn (string $f) => in_array($f, $def['filters'], true);
    $asAt = $def['asAt'] ?? false;
@endphp
<x-layouts.app :title="$result->title">
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('reports.index') }}" class="text-sm">← All reports</a>
                <h2 class="mt-1 text-xl font-semibold text-slate-900">{{ $result->title }}</h2>
                <div class="text-sm text-slate-500">{{ $result->subtitle }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('reports.print', ['report' => $key] + $query) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Print</a>
                @if($canExport)
                    <a href="{{ route('reports.export', ['report' => $key, 'format' => 'pdf'] + $query) }}" class="btn btn-secondary btn-sm" data-no-loading>PDF</a>
                    <a href="{{ route('reports.export', ['report' => $key, 'format' => 'xlsx'] + $query) }}" class="btn btn-secondary btn-sm" data-no-loading>Excel</a>
                    <a href="{{ route('reports.export', ['report' => $key, 'format' => 'csv'] + $query) }}" class="btn btn-secondary btn-sm" data-no-loading>CSV</a>
                @endif
            </div>
        </div>

        <form method="GET" action="{{ route('reports.show', $key) }}" class="card card-body flex flex-wrap items-end gap-3" x-data="{ preset: @js($filters->preset) }">
            @unless($asAt)
                <div><label class="label" for="preset">Period</label>
                    <select id="preset" name="preset" class="input w-44" x-model="preset">@foreach(ReportFilters::PRESETS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div x-show="preset === 'custom'" x-cloak><label class="label" for="from">From</label><input id="from" type="date" name="from" value="{{ $filters->from }}" class="input w-40"></div>
                <div x-show="preset === 'custom'" x-cloak><label class="label" for="to">To</label><input id="to" type="date" name="to" value="{{ $filters->to }}" class="input w-40"></div>
            @endunless
            @if($uses('group'))
                <div><label class="label" for="group">Group by</label>
                    <select id="group" name="group" class="input w-32">@foreach(Periods::GROUPS as $k => $v)<option value="{{ $k }}" @selected($filters->group === $k)>{{ $v }}</option>@endforeach</select></div>
            @endif
            @if($uses('customer'))
                <div><label class="label" for="customer_id">Customer</label>
                    <select id="customer_id" name="customer_id" class="input w-48"><option value="">All</option>@foreach($options['customers'] as $c)<option value="{{ $c->id }}" @selected($filters->id('customer_id') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            @endif
            @if($uses('supplier'))
                <div><label class="label" for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id" class="input w-48"><option value="">All</option>@foreach($options['suppliers'] as $s)<option value="{{ $s->id }}" @selected($filters->id('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            @endif
            @if($uses('category'))
                <div><label class="label" for="category_id">Category</label>
                    <select id="category_id" name="category_id" class="input w-44"><option value="">All</option>@foreach($options['categories'] as $c)<option value="{{ $c->id }}" @selected($filters->id('category_id') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            @endif
            @if($uses('user'))
                <div><label class="label" for="user_id">Cashier</label>
                    <select id="user_id" name="user_id" class="input w-44"><option value="">All</option>@foreach($options['users'] as $u)<option value="{{ $u->id }}" @selected($filters->id('user_id') === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
            @endif
            @if($uses('technician'))
                <div><label class="label" for="technician_id">Technician</label>
                    <select id="technician_id" name="technician_id" class="input w-44"><option value="">All</option>@foreach($options['users'] as $u)<option value="{{ $u->id }}" @selected($filters->id('technician_id') === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
            @endif
            @if($uses('method'))
                <div><label class="label" for="method_id">Paid by</label>
                    <select id="method_id" name="method_id" class="input w-36"><option value="">Any</option>@foreach($options['methods'] as $m)<option value="{{ $m->id }}" @selected($filters->id('method_id') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
            @endif
            @if($uses('status') && ! empty($def['statuses']))
                <div><label class="label" for="status">Show</label>
                    <select id="status" name="status" class="input w-44">@foreach($def['statuses'] as $k => $v)<option value="{{ $k }}" @selected((string) $filters->str('status') === (string) $k)>{{ $v }}</option>@endforeach</select></div>
            @endif
            <button class="btn btn-primary" data-loading-text="Working…">Show report</button>
        </form>

        @if($result->rowCount() > 8)
            <div x-data="{ q: '' }" class="max-w-sm">
                <label class="label" for="report_find">Find in this report</label>
                <input id="report_find" type="search" x-model="q" @input="document.querySelectorAll('[data-report-row]').forEach(r => { r.hidden = q !== '' && !r.textContent.toLowerCase().includes(q.toLowerCase()); })" class="input" placeholder="Type a name, number or word" autocomplete="off">
            </div>
        @endif

        @forelse($result->tables as $t)
            <div class="card overflow-x-auto">
                @if($t['title'])<div class="card-header"><h3 class="card-title">{{ $t['title'] }}</h3></div>@endif
                <table class="table">
                    <thead><tr>@foreach($t['columns'] as $c)<th class="{{ ReportResult::numeric($c['type']) ? 'text-right' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
                    <tbody>
                    @forelse($t['rows'] as $row)
                        <tr data-report-row>
                            @foreach($t['columns'] as $i => $c)
                                @php($text = ReportResult::display($c['type'], $row[$c['key']] ?? null))
                                <td class="{{ ReportResult::numeric($c['type']) ? 'whitespace-nowrap text-right' : '' }} {{ $c['type'] === 'date' || $c['type'] === 'datetime' ? 'whitespace-nowrap' : '' }}">
                                    @if($i === 0 && ! empty($row['_href']))<a href="{{ $row['_href'] }}" class="font-medium">{{ $text }}</a>@else{{ $text }}@endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($t['columns']) }}" class="px-4 py-10 text-center text-slate-500">Nothing to show for these dates.</td></tr>
                    @endforelse
                    </tbody>
                    @if($t['totals'] !== null && $t['rows'] !== [])
                        <tfoot><tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                            @foreach($t['columns'] as $c)<td class="{{ ReportResult::numeric($c['type']) ? 'whitespace-nowrap text-right' : '' }}">{{ ReportResult::display($c['type'], $t['totals'][$c['key']] ?? null) }}</td>@endforeach
                        </tr></tfoot>
                    @endif
                </table>
            </div>
        @empty
            <div class="card card-body text-sm text-slate-500">You are not allowed to see any of the figures in this report.</div>
        @endforelse

        @foreach($result->notes as $n)<p class="text-xs text-slate-500">{{ $n }}</p>@endforeach
    </div>
</x-layouts.app>
