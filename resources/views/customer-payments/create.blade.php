@php
    use App\Support\Money;
    $billData = $bills->map(fn ($b) => [
        'id' => $b['key'], 'no' => $b['no'], 'date' => $b['date']->format('d M Y'), 'total' => $b['total'], 'due' => $b['due'],
    ])->values();
    $focusBill = $focus ? $bills->firstWhere('key', (string) $focus) : null;
@endphp
<x-layouts.app title="Collect a payment">
    <div class="mx-auto max-w-5xl space-y-5">
        @if(! $customer)
            <form method="GET" class="card card-body max-w-xl space-y-3">
                <h2 class="card-title">Whose payment is it?</h2>
                <select name="customer" class="input" required>
                    <option value="">Choose…</option>
                    @foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }} @if((float) $c->balance > 0) · owes {{ Money::format($c->balance) }}@endif</option>@endforeach
                </select>
                <div><button class="btn btn-primary">Continue</button></div>
            </form>
        @else
            <script>
                window.collectForm = function (bills, initial) {
                    const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
                    const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
                    return {
                        bills, amount: initial.amount, mode: initial.mode, alloc: initial.alloc,
                        // oldest invoices first, exactly as the server will do it
                        get autoPlan() {
                            let left = num(this.amount); const plan = {};
                            for (const b of this.bills) { const part = r2(Math.min(left, num(b.due))); if (part > 0) { plan[b.id] = part; left = r2(left - part); } }
                            return plan;
                        },
                        get planned() { return this.mode === 'auto' ? this.autoPlan : (this.mode === 'manual' ? this.alloc : {}); },
                        get plannedTotal() { return r2(Object.values(this.planned).reduce((s, v) => s + num(v), 0)); },
                        get advance() { return r2(num(this.amount) - this.plannedTotal); },
                        get tooMuchOnBill() { return this.bills.some(b => num(this.planned[b.id]) > num(b.due) + 0.001); },
                        get invalid() { return num(this.amount) <= 0 || this.plannedTotal > num(this.amount) + 0.001 || this.tooMuchOnBill || (this.mode === 'manual' && this.plannedTotal <= 0); },
                        payFull(b) { this.alloc[b.id] = b.due; this.mode = 'manual'; if (num(this.amount) < this.plannedTotal) { this.amount = this.plannedTotal; } },
                        money(v) { return (num(v)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                    };
                };
            </script>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ $customer->name }}</div>
                    <a href="{{ route('customer-payments.create') }}" class="text-xs">choose another</a></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Unpaid invoices</div><div class="mt-1 text-2xl font-semibold {{ (float) $owed > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ Money::withSymbol($owed) }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account balance</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol(abs((float) $customer->balance)) }}</div>
                    <div class="text-xs text-slate-500">{{ (float) $customer->balance > 0 ? 'owes you' : ((float) $customer->balance < 0 ? 'you hold their credit' : 'nothing owed either way') }}@if((float) $advance > 0) · {{ Money::format($advance) }} paid in advance @endif</div></div>
            </div>

            <form method="POST" action="{{ route('customer-payments.store') }}" class="space-y-5"
                  x-data="collectForm(@js($billData), @js([
                      'amount' => old('amount', $focusBill ? $focusBill['due'] : ''),
                      'mode' => old('allocation', $focusBill ? 'manual' : 'auto'),
                      'alloc' => (object) (old('allocations') ?? ($focusBill ? [$focusBill['key'] => $focusBill['due']] : [])),
                  ]))">
                @csrf
                <input type="hidden" name="customer_id" value="{{ $customer->id }}">

                <div class="card">
                    <div class="card-header"><h2 class="card-title">Payment received</h2></div>
                    <div class="card-body grid gap-4 sm:grid-cols-4">
                        <div><label class="label" for="amount">Amount received <span class="text-red-500">*</span></label>
                            <input id="amount" name="amount" type="number" step="0.01" min="0.01" x-model="amount" required class="input {{ $errors->has('amount') ? 'input-error' : '' }}" autofocus>
                            @error('amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                        <div><label class="label" for="payment_method_id">Paid by <span class="text-red-500">*</span></label>
                            <select id="payment_method_id" name="payment_method_id" class="input" required>
                                @foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) old('payment_method_id') === $m->id)>{{ $m->name }}{{ $m->requires_reference ? ' (needs a reference)' : '' }}</option>@endforeach
                            </select>
                            @error('payment_method_id')<p class="error-text">{{ $message }}</p>@enderror</div>
                        <x-form.input name="reference_no" label="Reference" help="Cheque, transaction or slip number" class="sm:col-span-2" />
                        <x-form.input name="notes" label="Note (optional)" class="sm:col-span-4" />
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2 class="card-title">Which invoices does it pay?</h2></div>
                    <div class="card-body space-y-3">
                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" name="allocation" value="auto" x-model="mode" class="h-4 w-4 text-brand-600"> Oldest invoices first <span class="text-slate-500">(recommended)</span></label>
                            <label class="flex items-center gap-2"><input type="radio" name="allocation" value="manual" x-model="mode" class="h-4 w-4 text-brand-600"> I will choose the invoices</label>
                            <label class="flex items-center gap-2"><input type="radio" name="allocation" value="advance" x-model="mode" class="h-4 w-4 text-brand-600"> Keep it as an advance (credit)</label>
                        </div>
                        @error('allocation')<p class="error-text">{{ $message }}</p>@enderror

                        @if($bills->isEmpty())
                            <p class="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-500">This customer has no unpaid invoices. Whatever they pay now is kept as credit that they can spend at the till.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="table">
                                    <thead><tr><th>Invoice</th><th>Date</th><th class="text-right">Total</th><th class="text-right">Still due</th><th class="text-right">This payment pays</th></tr></thead>
                                    <tbody>
                                    <template x-for="b in bills" :key="b.id">
                                        <tr>
                                            <td class="font-mono text-xs font-medium" x-text="b.no"></td>
                                            <td class="whitespace-nowrap" x-text="b.date"></td>
                                            <td class="text-right text-slate-500" x-text="money(b.total)"></td>
                                            <td class="text-right font-medium" x-text="money(b.due)"></td>
                                            <td class="text-right">
                                                <template x-if="mode === 'manual'">
                                                    <span class="inline-flex items-center gap-1">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="payFull(b)">full</button>
                                                        <input type="number" step="0.01" min="0" :max="b.due" :name="'allocations[' + b.id + ']'" x-model="alloc[b.id]" class="input w-28 text-right">
                                                    </span>
                                                </template>
                                                <template x-if="mode !== 'manual'"><span :class="planned[b.id] ? 'font-semibold text-emerald-700' : 'text-slate-300'" x-text="planned[b.id] ? money(planned[b.id]) : '—'"></span></template>
                                            </td>
                                        </tr>
                                    </template>
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-slate-50 px-4 py-3 text-sm">
                            <span>Pays invoices: <strong x-text="money(plannedTotal)"></strong></span>
                            <span>Kept as credit: <strong :class="advance > 0 ? 'text-blue-700' : ''" x-text="money(advance)"></strong></span>
                            <span class="text-red-600" x-show="tooMuchOnBill" x-cloak>An invoice is paid more than it owes.</span>
                            <span class="text-red-600" x-show="plannedTotal > (parseFloat(amount) || 0) + 0.001" x-cloak>More given to invoices than the payment.</span>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <a href="{{ route('customers.show', $customer) }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="invalid" data-loading-text="Saving…">Record payment</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
