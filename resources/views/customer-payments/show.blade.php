@php
    use App\Support\Money;
@endphp
<x-layouts.app :title="'Payment '.$payment->receipt_no">
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $payment->receipt_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ Money::withSymbol($payment->amount) }} from
                    @if($payment->customer->is_walk_in)walk-in customer @else<a href="{{ route('customers.show', $payment->customer_id) }}" class="text-slate-900">{{ $payment->customer->name }}</a>@endif</h2>
                <div class="mt-1 text-sm text-slate-500">{{ $payment->payment_date->format('d M Y, H:i') }} · {{ $payment->method->name }}@if($payment->reference_no) · ref {{ $payment->reference_no }}@endif · entered by {{ $payment->creator?->name ?? 'system' }}
                    @if($payment->sale_id) · taken at the till for <a href="{{ route('sales.show', $payment->sale_id) }}">{{ $payment->notes }}</a>@endif</div>
                @if($payment->notes && ! $payment->sale_id)<p class="mt-2 text-sm text-slate-700">{{ $payment->notes }}</p>@endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($payment->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@else<span class="badge badge-green text-sm">Recorded</span>@endif
                @if($receiptUrl)<a href="{{ $receiptUrl }}" target="_blank" class="btn btn-primary btn-sm" data-no-loading>Print receipt</a>@endif
                @can('sales.reprint')<a href="{{ route('customer-payments.reprint', $payment) }}" target="_blank" class="btn btn-secondary btn-sm">Print receipt (copy)</a>@endcan
            </div>
        </div>

        @if($payment->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Cancelled by {{ $payment->voider?->name }} on {{ $payment->voided_at->format('d M Y, H:i') }}: “{{ $payment->void_reason }}”.
                The invoices it had settled are open again and the customer's account was corrected.
            </div>
        @endif

        <div class="card">
            <div class="card-header"><h2 class="card-title">Invoices settled by this payment</h2></div>
            @if($payment->allocations->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500">This payment is not applied to any invoice: it is the customer's credit.</p>
            @else
                <table class="table">
                    <thead><tr><th>Invoice</th><th>Status</th><th class="text-right">Applied</th></tr></thead>
                    <tbody>
                    @foreach($payment->allocations as $a)
                        <tr>
                            <td class="font-mono text-xs"><a href="{{ route('sales.show', $a->sale) }}">{{ $a->sale->invoice_no }}</a></td>
                            <td>@if($a->sale->isVoided())<span class="badge badge-red">invoice cancelled: now credit</span>@elseif($a->sale->payment_status === 'paid')<span class="badge badge-green">paid</span>@else<span class="badge badge-amber">{{ $a->sale->payment_status }}</span>@endif</td>
                            <td class="text-right">{{ Money::format($a->amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @unless($payment->isVoided() || $payment->customer->is_walk_in)
                <div class="border-t border-slate-200 px-5 py-3 text-sm">Not applied to any invoice (credit): <strong class="{{ (float) $free > 0 ? 'text-blue-700' : '' }}">{{ Money::format($free) }}</strong></div>
            @endunless
        </div>

        @can('customer_payments.void')
            @if(! $payment->isVoided() && ! $payment->customer->is_walk_in)
                <form method="POST" action="{{ route('customer-payments.void', $payment) }}" class="card card-body space-y-3"
                      data-confirm="Cancel payment {{ $payment->receipt_no }}? The invoices it settled will be open again." data-confirm-label="Cancel payment" data-danger="1">
                    @csrf
                    <h3 class="card-title">Recorded by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling writes an opposite entry on the customer's account; nothing is deleted. Then enter the correct payment.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this payment</button></div>
                </form>
            @endif
        @endcan

        <a href="{{ route('customer-payments.index') }}" class="inline-block text-sm">← All customer payments</a>
    </div>

    @if($autoPrint && $receiptUrl)
        <iframe src="{{ $receiptUrl }}" title="Receipt" aria-hidden="true" style="position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden"></iframe>
    @endif
</x-layouts.app>
