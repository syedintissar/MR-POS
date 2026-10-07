@php
    use App\Support\Money;
@endphp
<x-layouts.app :title="'Payment '.$payment->payment_no">
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $payment->payment_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ Money::withSymbol($payment->amount) }} to
                    @can('suppliers.view')<a href="{{ route('suppliers.show', $payment->supplier_id) }}" class="text-slate-900">{{ $payment->supplier->name }}</a>@else{{ $payment->supplier->name }}@endcan</h2>
                <div class="mt-1 text-sm text-slate-500">{{ $payment->payment_date->format('d M Y') }} · {{ $payment->method->name }}@if($payment->reference_no) · ref {{ $payment->reference_no }}@endif · entered by {{ $payment->creator?->name ?? 'system' }}</div>
                @if($payment->notes)<p class="mt-2 text-sm text-slate-700">{{ $payment->notes }}</p>@endif
            </div>
            <div>@if($payment->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@else<span class="badge badge-green text-sm">Recorded</span>@endif</div>
        </div>

        @if($payment->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Cancelled by {{ $payment->voider?->name }} on {{ $payment->voided_at->format('d M Y, H:i') }}: “{{ $payment->void_reason }}”.
                The bills it had settled are open again and the supplier's account was corrected.
            </div>
        @endif

        <div class="card">
            <div class="card-header"><h2 class="card-title">Bills settled by this payment</h2></div>
            @if($payment->allocations->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500">This payment is not applied to any bill: it is an advance.</p>
            @else
                <table class="table">
                    <thead><tr><th>Bill</th><th>Their invoice</th><th>Status</th><th class="text-right">Applied</th></tr></thead>
                    <tbody>
                    @foreach($payment->allocations as $a)
                        <tr>
                            <td class="font-mono text-xs"><a href="{{ route('purchases.show', $a->purchase) }}">{{ $a->purchase->label() }}</a></td>
                            <td>{{ $a->purchase->supplier_invoice_no }}</td>
                            <td>@if($a->purchase->isVoided())<span class="badge badge-red">bill cancelled: now an advance</span>@elseif($a->purchase->payment_status === 'paid')<span class="badge badge-green">paid</span>@else<span class="badge badge-amber">{{ $a->purchase->payment_status }}</span>@endif</td>
                            <td class="text-right">{{ Money::format($a->amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @unless($payment->isVoided())
                <div class="border-t border-slate-200 px-5 py-3 text-sm">
                    Not applied to any bill (advance): <strong class="{{ (float) $advance > 0 ? 'text-blue-700' : '' }}">{{ Money::format($advance) }}</strong>
                </div>
            @endunless
        </div>

        @if($bills->isNotEmpty())
            @can('supplier_payments.create')
                <form method="POST" action="{{ route('supplier-payments.allocate', $payment) }}" class="card"
                      x-data="{ alloc: {}, get total() { return Object.values(this.alloc).reduce((s, v) => s + (parseFloat(v) || 0), 0); } }">
                    @csrf
                    <div class="card-header"><h2 class="card-title">Apply the advance ({{ Money::format($advance) }}) to open bills</h2></div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Bill</th><th>Date</th><th class="text-right">Still due</th><th class="text-right">Apply</th></tr></thead>
                            <tbody>
                            @foreach($bills as $b)
                                <tr>
                                    <td class="font-mono text-xs">{{ $b->purchase_no }}</td><td>{{ $b->purchase_date->format('d M Y') }}</td>
                                    <td class="text-right">{{ Money::format($b->due_amount) }}</td>
                                    <td class="text-right"><input type="number" step="0.01" min="0" max="{{ min((float) $b->due_amount, (float) $advance) }}" name="allocations[{{ $b->id }}]" x-model="alloc[{{ $b->id }}]" class="input ml-auto w-28 text-right"></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3">
                        <span class="text-sm text-slate-600">Applying: <strong x-text="total.toFixed(2)"></strong> of {{ Money::format($advance) }}</span>
                        <button class="btn btn-primary" :disabled="total <= 0 || total > {{ (float) $advance }} + 0.001">Apply to bills</button>
                    </div>
                </form>
            @endcan
        @endif

        @can('supplier_payments.void')
            @unless($payment->isVoided())
                <form method="POST" action="{{ route('supplier-payments.void', $payment) }}" class="card card-body space-y-3"
                      data-confirm="Cancel payment {{ $payment->payment_no }}? The bills it settled will be open again." data-confirm-label="Cancel payment" data-danger="1">
                    @csrf
                    <h3 class="card-title">Recorded by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling writes an opposite entry on the supplier's account; nothing is deleted.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this payment</button></div>
                </form>
            @endunless
        @endcan

        <a href="{{ route('supplier-payments.index') }}" class="inline-block text-sm">← All supplier payments</a>
    </div>
</x-layouts.app>
