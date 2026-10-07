@php($user = auth()->user())
<x-layouts.app :title="'Adjustment '.$adjustment->adjustment_no">
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $adjustment->adjustment_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $adjustment->reasonLabel() }}</h2>
                <div class="mt-1 text-sm text-slate-500">{{ $adjustment->adjustment_date->format('d M Y') }} · entered by {{ $adjustment->creator?->name ?? 'system' }} on {{ $adjustment->created_at->format('d M Y, H:i') }}</div>
                @if($adjustment->notes)<p class="mt-2 text-sm text-slate-700">{{ $adjustment->notes }}</p>@endif
            </div>
            <div>@if($adjustment->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@else<span class="badge badge-green text-sm">Posted</span>@endif</div>
        </div>

        @if($adjustment->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Cancelled by {{ $adjustment->voider?->name }} on {{ $adjustment->voided_at->format('d M Y, H:i') }}: “{{ $adjustment->void_reason }}”. The stock changes were reversed; both sets of movements stay in the stock card.
            </div>
        @endif

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Product</th><th class="text-right">Before</th><th class="text-right">Change</th><th class="text-right">After</th>@if($showCost)<th class="text-right">Cost each</th>@endif<th>Note</th></tr></thead>
                <tbody>
                @foreach($adjustment->items as $item)
                    @php($m = ($movements[$item->id] ?? collect())->first())
                    <tr>
                        <td><a href="{{ route('products.show', $item->product) }}" class="font-medium text-slate-900">{{ $item->product->name }}</a><div class="font-mono text-xs text-slate-500">{{ $item->product->sku }}</div></td>
                        <td class="text-right text-slate-500">{{ $m ? \App\Support\Decimal::trim($m->qty_before) : '' }}</td>
                        <td class="text-right font-medium {{ (float) $item->quantity < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ (float) $item->quantity > 0 ? '+' : '' }}{{ \App\Support\Decimal::trim($item->quantity) }} <span class="text-xs font-normal text-slate-400">{{ $item->product->unit->short_name }}</span></td>
                        <td class="text-right">{{ $m ? \App\Support\Decimal::trim($m->qty_after) : '' }}</td>
                        @if($showCost)<td class="text-right text-slate-500">{{ number_format((float) $item->unit_cost, 2) }}</td>@endif
                        <td class="text-xs text-slate-500">{{ $item->notes }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @can('inventory.void_adjustment')
            @unless($adjustment->isVoided())
                <form method="POST" action="{{ route('adjustments.void', $adjustment) }}" class="card card-body space-y-3"
                      data-confirm="Cancel adjustment {{ $adjustment->adjustment_no }}? The stock changes will be reversed." data-confirm-label="Cancel adjustment" data-danger="1">
                    @csrf
                    <h3 class="card-title">Entered by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling writes the opposite stock movements; nothing is deleted. It is refused if the stock needed to undo it is no longer there.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this adjustment</button></div>
                </form>
            @endunless
        @endcan
        <a href="{{ route('adjustments.index') }}" class="inline-block text-sm">← All adjustments</a>
    </div>
</x-layouts.app>
