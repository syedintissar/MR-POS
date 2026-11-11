@php use App\Support\Money; use App\Support\Decimal; @endphp
<x-layouts.app title="Day closings">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="result">Result</label>
                <select id="result" name="result" class="input w-36"><option value="">All</option>@foreach(['exact' => 'Exact', 'short' => 'Short', 'over' => 'Over'] as $k => $v)<option value="{{ $k }}" @selected(request('result') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label class="label" for="status">Status</label>
                <select id="status" name="status" class="input w-32">@foreach(['posted' => 'Recorded', 'voided' => 'Cancelled', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        @can('cashbook.close_day')<a href="{{ route('cashbook.closings.create') }}" class="btn btn-primary">Close the day</a>@endcan
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>No.</th><th>Counted on</th><th>Account</th><th class="text-right">The book said</th><th class="text-right">Counted</th><th class="text-right">Difference</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse($closings as $c)
                <tr class="{{ $c->isVoided() ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('cashbook.closings.show', $c) }}">{{ $c->closing_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $c->closed_at->format('d M Y, H:i') }}</td>
                    <td>{{ $c->account->name }}</td>
                    <td class="text-right">{{ Money::format($c->expected_amount) }}</td>
                    <td class="text-right font-medium">{{ Money::format($c->counted_amount) }}</td>
                    <td class="text-right">
                        @if($c->result() === 'exact')<span class="badge badge-green">Exact</span>
                        @elseif($c->result() === 'short')<span class="badge badge-red">Short {{ Money::format(Decimal::abs((string) $c->difference, 2)) }}</span>
                        @else<span class="badge badge-amber">Over {{ Money::format($c->difference) }}</span>@endif
                    </td>
                    <td>{{ $c->creator?->name }}</td>
                    <td class="text-right">@if($c->isVoided())<span class="badge badge-red">Cancelled</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">The drawer has not been counted yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $closings->links() }}</div>
    <a href="{{ route('cashbook.index') }}" class="mt-4 inline-block text-sm">← Cash &amp; bank</a>
</x-layouts.app>
