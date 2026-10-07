@php
    use App\Support\Decimal;
    use App\Support\Money;
@endphp
<x-layouts.app :title="'Purchase '.$purchase->label()">
    <div class="mx-auto max-w-5xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $purchase->label() }}</div>
                <h2 class="text-xl font-semibold text-slate-900">
                    @can('suppliers.view')<a href="{{ route('suppliers.show', $purchase->supplier_id) }}" class="text-slate-900">{{ $purchase->supplier->name }}</a>@else{{ $purchase->supplier->name }}@endcan
                </h2>
                <div class="mt-1 text-sm text-slate-500">
                    {{ $purchase->purchase_date->format('d M Y') }}
                    @if($purchase->supplier_invoice_no) · their invoice <span class="font-medium text-slate-700">{{ $purchase->supplier_invoice_no }}</span>@endif
                    · entered by {{ $purchase->creator?->name ?? 'system' }}
                    @if($purchase->received_at) · received by {{ $purchase->receiver?->name }} on {{ $purchase->received_at->format('d M Y, H:i') }}@endif
                </div>
                @if($purchase->notes)<p class="mt-2 text-sm text-slate-700">{{ $purchase->notes }}</p>@endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($purchase->isDraft())<span class="badge badge-gray text-sm">Draft</span>
                @elseif($purchase->isVoided())<span class="badge badge-red text-sm">Cancelled</span>
                @elseif($purchase->payment_status === 'paid')<span class="badge badge-green text-sm">Paid</span>
                @elseif($purchase->payment_status === 'partial')<span class="badge badge-amber text-sm">Part paid</span>
                @else<span class="badge badge-blue text-sm">Unpaid</span>@endif
                <a href="{{ route('purchases.print', $purchase) }}" target="_blank" class="btn btn-secondary btn-sm">Print</a>
                @if($purchase->isDraft())
                    @can('purchases.create')<a href="{{ route('purchases.edit', $purchase) }}" class="btn btn-secondary btn-sm">Edit draft</a>@endcan
                @endif
                @if($purchase->isReceived() && (float) $purchase->due_amount > 0)
                    @can('supplier_payments.create')<a href="{{ route('supplier-payments.create', ['supplier' => $purchase->supplier_id, 'purchase' => $purchase->id]) }}" class="btn btn-primary btn-sm">Pay supplier</a>@endcan
                @endif
            </div>
        </div>

        @if($purchase->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Cancelled by {{ $purchase->voider?->name }} on {{ $purchase->voided_at->format('d M Y, H:i') }}: “{{ $purchase->void_reason }}”.
                The goods were taken out of stock again and the supplier's account was corrected; both sets of entries stay in the stock card and the account.
                Any money paid against this bill is kept as an advance with the supplier.
            </div>
        @endif

        @if($purchase->isDraft())
            <div class="rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                This is a <strong>draft</strong>: it has no number yet, no stock has been added and the supplier's account is untouched. Receive it when the goods arrive.
            </div>
        @endif

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Product</th><th class="text-right">Quantity</th><th class="text-right">Cost each</th><th class="text-right">Discount</th><th class="text-right">Tax</th><th class="text-right">Line total</th>@unless($purchase->isDraft())<th class="text-right">Landed each</th>@endunless</tr></thead>
                <tbody>
                @foreach($purchase->items as $item)
                    <tr>
                        <td><a href="{{ route('products.show', $item->product) }}" class="font-medium text-slate-900">{{ $item->product->name }}</a><div class="font-mono text-xs text-slate-500">{{ $item->product->sku }}</div></td>
                        <td class="text-right">{{ Decimal::trim((string) $item->quantity) }} <span class="text-xs text-slate-400">{{ $item->product->unit->short_name }}</span></td>
                        <td class="text-right text-slate-600">{{ Money::format($item->unit_cost, 2) }}</td>
                        <td class="text-right text-slate-500">{{ (float) $item->discount_amount ? Money::format($item->discount_amount) : '' }}</td>
                        <td class="text-right text-slate-500">{{ (float) $item->tax_amount ? Money::format($item->tax_amount) : '' }}</td>
                        <td class="text-right font-medium">{{ Money::format($item->line_total) }}</td>
                        @unless($purchase->isDraft())<td class="text-right text-slate-600" title="Cost including its share of freight, discount and tax">{{ Money::format($item->landed_unit_cost, 2) }}</td>@endunless
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="card card-body space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-slate-600">Products</span><span>{{ Money::format($purchase->subtotal) }}</span></div>
                @if((float) $purchase->discount_amount)<div class="flex justify-between"><span class="text-slate-600">Invoice discount</span><span>− {{ Money::format($purchase->discount_amount) }}</span></div>@endif
                @if((float) $purchase->tax_amount)<div class="flex justify-between"><span class="text-slate-600">Invoice tax</span><span>{{ Money::format($purchase->tax_amount) }}</span></div>@endif
                @if((float) $purchase->additional_cost)<div class="flex justify-between"><span class="text-slate-600">Freight / extra costs</span><span>{{ Money::format($purchase->additional_cost) }}</span></div>@endif
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Total</span><span>{{ Money::withSymbol($purchase->grand_total) }}</span></div>
                @if($purchase->isReceived())
                    <div class="flex justify-between text-emerald-700"><span>Paid</span><span>{{ Money::format($purchase->paid_amount) }}</span></div>
                    <div class="flex justify-between font-semibold {{ (float) $purchase->due_amount > 0 ? 'text-amber-700' : 'text-slate-500' }}"><span>Still to pay</span><span>{{ Money::format($purchase->due_amount) }}</span></div>
                @endif
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Payments against this bill</h2></div>
                @php($allocations = $purchase->allocations->sortBy('id'))
                @if($allocations->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">{{ $purchase->isDraft() ? 'Nothing is owed until the purchase is received.' : 'No payment has been made against this bill yet.' }}</p>
                @else
                    <table class="table">
                        <thead><tr><th>Payment</th><th>Date</th><th>By</th><th class="text-right">Applied</th></tr></thead>
                        <tbody>
                        @foreach($allocations as $a)
                            <tr class="{{ $a->payment->isVoided() ? 'text-slate-400 line-through' : '' }}">
                                <td class="font-mono text-xs">@can('supplier_payments.view')<a href="{{ route('supplier-payments.show', $a->payment) }}">{{ $a->payment->payment_no }}</a>@else{{ $a->payment->payment_no }}@endcan</td>
                                <td class="whitespace-nowrap">{{ $a->payment->payment_date->format('d M Y') }}</td>
                                <td>{{ $a->payment->method->name }}@if($a->payment->isVoided()) <span class="badge badge-red">cancelled</span>@endif</td>
                                <td class="text-right">{{ Money::format($a->amount) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @if($purchase->isDraft())
            @can('purchases.receive')
                <form method="POST" action="{{ route('purchases.receive', $purchase) }}" class="card card-body space-y-4"
                      data-confirm="Receive this purchase? The stock will be added and {{ $purchase->supplier->name }} will be owed {{ Money::withSymbol($purchase->grand_total) }}." data-confirm-label="Receive stock">
                    @csrf
                    <h3 class="card-title">Goods arrived</h3>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div><label class="label" for="pay_amount">Paid now (optional)</label>
                            <input id="pay_amount" name="pay_amount" type="number" step="0.01" min="0" value="{{ old('pay_amount') }}" class="input" placeholder="0 = on credit">
                            @error('pay_amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                        <div><label class="label" for="pay_method_id">Paid by</label>
                            <select id="pay_method_id" name="pay_method_id" class="input">@foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) old('pay_method_id') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
                        <x-form.input name="pay_reference" label="Reference" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button class="btn btn-primary" data-loading-text="Receiving…">Receive into stock</button>
                        <span class="text-xs text-slate-500">All or nothing: if anything is wrong, nothing is saved.</span>
                    </div>
                </form>
            @endcan
            @can('purchases.create')
                <form method="POST" action="{{ route('purchases.destroy', $purchase) }}" data-confirm="Delete this draft?" data-confirm-label="Delete draft" data-danger="1">
                    @csrf @method('DELETE')
                    <button class="btn btn-secondary text-red-600">Delete draft</button>
                </form>
            @endcan
        @endif

        @can('purchases.void')
            @if($purchase->isReceived())
                <form method="POST" action="{{ route('purchases.void', $purchase) }}" class="card card-body space-y-3"
                      data-confirm="Cancel purchase {{ $purchase->purchase_no }}? The goods will be taken out of stock again." data-confirm-label="Cancel purchase" data-danger="1">
                    @csrf
                    <h3 class="card-title">Entered by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling writes the opposite stock and account entries; nothing is deleted.
                        It is refused if some of these goods have already been sold or used, or if goods were returned to the supplier.
                        Money already paid on this bill stays with the supplier as an advance.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this purchase</button></div>
                </form>
            @endif
        @endcan

        <a href="{{ route('purchases.index') }}" class="inline-block text-sm">← All purchases</a>
    </div>
</x-layouts.app>
