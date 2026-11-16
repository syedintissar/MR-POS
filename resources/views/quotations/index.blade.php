@php use App\Support\Money; @endphp
<x-layouts.app title="Quotations">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="q">Find</label><input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="QTN-000123, name or phone" class="input w-56" autofocus></div>
            <div><label class="label" for="status">Show</label>
                <select id="status" name="status" class="input w-36">
                    @foreach(['open' => 'Open', 'expired' => 'Expired', 'accepted' => 'Accepted (sold)', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach
                </select></div>
            <button class="btn btn-secondary" data-no-loading>Filter</button>
        </form>
        @if($canCreate)<a href="{{ route('quotations.create') }}" class="btn btn-primary">+ New quotation</a>@endif
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>No.</th><th>Date</th><th>For</th><th>Valid until</th><th class="text-right">Total</th><th>Status</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse($quotations as $qt)
                <tr class="{{ $qt->status === 'cancelled' ? 'text-slate-400' : '' }}">
                    <td class="font-mono text-xs font-medium"><a href="{{ route('quotations.show', $qt) }}">{{ $qt->quotation_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $qt->quote_date->format('d M Y') }}</td>
                    <td>{{ $qt->customer_name }}@if($qt->customer_phone)<div class="text-xs text-slate-500">{{ $qt->customer_phone }}</div>@endif</td>
                    <td class="whitespace-nowrap">{{ $qt->valid_until?->format('d M Y') ?? '—' }}</td>
                    <td class="text-right font-medium">{{ Money::format($qt->grand_total) }}</td>
                    <td>
                        @if($qt->isExpired())<span class="badge badge-amber">Expired</span>
                        @elseif($qt->status === 'open')<span class="badge badge-blue">Open</span>
                        @elseif($qt->status === 'accepted')<span class="badge badge-green">Accepted</span>
                        @else<span class="badge badge-red">Cancelled</span>@endif
                    </td>
                    <td>{{ $qt->creator?->name }}</td>
                    <td class="text-right"><a href="{{ route('quotations.show', $qt) }}" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No quotations here. @if($canCreate)<a href="{{ route('quotations.create') }}">Write one.</a>@endif</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $quotations->links() }}</div>
</x-layouts.app>
