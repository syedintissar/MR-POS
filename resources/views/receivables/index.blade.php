@php
    use App\Support\Money;
    $totals = $report['totals'];
@endphp
<x-layouts.app title="Who owes us">
    <style>
        @media print {
            aside, header, footer, .no-print, [x-cloak] { display: none !important; }
            .lg\:pl-64 { padding-left: 0 !important; }
            main { padding: 0 !important; }
            body { background: #fff; }
        }
    </style>
    <div class="no-print mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label">Customer</label>
                <select name="customer_id" class="input w-56"><option value="">All customers</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected($customerId === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="detail" value="1" @checked($detail) class="h-4 w-4 rounded border-slate-300 text-brand-600"> list every invoice</label>
            <button class="btn btn-secondary" data-no-loading>Show</button>
        </form>
        <button type="button" class="btn btn-secondary" onclick="window.print()" data-no-loading>Print</button>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Unpaid invoices</div><div class="mt-1 text-2xl font-semibold {{ (float) $totals['due'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ Money::withSymbol($totals['due']) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Over 60 days</div><div class="mt-1 text-2xl font-semibold {{ (float) bcadd($totals['d61_90'], $totals['d90'], 2) > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ Money::withSymbol(bcadd($totals['d61_90'], $totals['d90'], 2)) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Net of all customer accounts</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($totals['balance']) }}</div><div class="text-xs text-slate-500">invoices + opening balances − credit held</div></div>
    </div>

    <div class="card overflow-x-auto">
        <div class="card-header"><h2 class="card-title">Aging of unpaid invoices (by invoice date) · {{ now()->format('d M Y') }}</h2></div>
        <table class="table">
            <thead>
            <tr><th>Customer</th>@foreach($buckets as $label)<th class="text-right">{{ $label }}</th>@endforeach<th class="text-right">Invoices due</th><th class="text-right" title="Opening balance, corrections, and credit the customer holds">Other</th><th class="text-right">Balance</th></tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $row)
                @php($c = $row['customer'])
                <tr>
                    <td><a href="{{ route('customers.show', $c) }}" class="font-medium text-slate-900">{{ $c->name }}</a><div class="text-xs text-slate-500">{{ $c->phone }}</div></td>
                    @foreach($buckets as $key => $label)<td class="text-right {{ $key === 'd90' && (float) $row['buckets'][$key] > 0 ? 'font-semibold text-red-600' : '' }}">{{ (float) $row['buckets'][$key] ? Money::format($row['buckets'][$key]) : '' }}</td>@endforeach
                    <td class="text-right font-medium">{{ (float) $row['due'] ? Money::format($row['due']) : '' }}</td>
                    <td class="text-right text-slate-500">{{ (float) $row['other'] ? Money::format($row['other']) : '' }}</td>
                    <td class="text-right font-semibold {{ (float) $row['balance'] < 0 ? 'text-blue-700' : '' }}">{{ Money::format($row['balance']) }}</td>
                </tr>
                @if($detail)
                    @foreach($row['invoices'] as $inv)
                        <tr class="bg-slate-50 text-xs text-slate-600">
                            <td class="pl-8"><a href="{{ route('sales.show', $inv['id']) }}" class="font-mono">{{ $inv['invoice_no'] }}</a> · {{ \Illuminate\Support\Carbon::parse($inv['date'])->format('d M Y') }}</td>
                            <td colspan="{{ count($buckets) }}" class="text-right">{{ $inv['age'] }} day(s) old · total {{ Money::format($inv['total']) }} · paid {{ Money::format($inv['paid']) }}</td>
                            <td class="text-right font-medium">{{ Money::format($inv['due']) }}</td><td></td><td></td>
                        </tr>
                    @endforeach
                @endif
            @empty
                <tr><td colspan="{{ count($buckets) + 4 }}" class="px-4 py-12 text-center text-slate-500">Nobody owes the shop anything.</td></tr>
            @endforelse
            </tbody>
            @if($report['rows'])
                <tfoot><tr class="bg-slate-50 font-semibold">
                    <td>Total</td>@foreach($buckets as $key => $label)<td class="px-4 py-2 text-right">{{ Money::format($totals[$key]) }}</td>@endforeach
                    <td class="px-4 py-2 text-right">{{ Money::format($totals['due']) }}</td><td class="px-4 py-2 text-right">{{ Money::format($totals['other']) }}</td><td class="px-4 py-2 text-right">{{ Money::format($totals['balance']) }}</td>
                </tr></tfoot>
            @endif
        </table>
        <p class="border-t border-slate-200 px-5 py-2 text-xs text-slate-500">“Other” is what a customer owes that is not on an invoice (an opening balance or a correction) minus money they have paid in advance, so every row adds up to the customer's balance.</p>
    </div>
</x-layouts.app>
