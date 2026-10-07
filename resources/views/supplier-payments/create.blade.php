@php
    use App\Support\Money;
    $billData = $bills->map(fn ($b) => [
        'id' => $b->id, 'no' => $b->purchase_no, 'date' => $b->purchase_date->format('d M Y'), 'invoice' => $b->supplier_invoice_no,
        'total' => (string) $b->grand_total, 'due' => (string) $b->due_amount,
    ])->values();
    $focusBill = $focus ? $bills->firstWhere('id', $focus) : null;
@endphp
<x-layouts.app title="Pay a supplier">
    <div class="mx-auto max-w-5xl space-y-5">
        @if(! $supplier)
            <form method="GET" class="card card-body max-w-xl space-y-3">
                <h2 class="card-title">Which supplier are you paying?</h2>
                <select name="supplier" class="input" required>
                    <option value="">Choose…</option>
                    @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }} @if((float) $s->balance > 0) · you owe {{ Money::format($s->balance) }}@endif</option>@endforeach
                </select>
                <div><button class="btn btn-primary">Continue</button></div>
            </form>
        @else
            <script>
                window.paymentForm = function (bills, initial) {
                    const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
                    const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
                    return {
                        bills, amount: initial.amount, mode: initial.mode, alloc: initial.alloc,
                        // oldest bills first, exactly as the server will do it
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
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Supplier</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ $supplier->name }}</div>
                    <a href="{{ route('supplier-payments.create') }}" class="text-xs">choose another</a></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Open bills total</div><div class="mt-1 text-2xl font-semibold {{ (float) $owed > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ Money::withSymbol($owed) }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account balance</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol(abs((float) $supplier->balance)) }}</div>
                    <div class="text-xs text-slate-500">{{ (float) $supplier->balance > 0 ? 'you owe the supplier' : ((float) $supplier->balance < 0 ? 'the supplier holds an advance of yours' : 'nothing owed either way') }}</div></div>
            </div>

            <form method="POST" action="{{ route('supplier-payments.store') }}" class="space-y-5"
                  x-data="paymentForm(@js($billData), @js([
                      'amount' => old('amount', $focusBill ? (string) $focusBill->due_amount : ''),
                      'mode' => old('allocation', $focusBill ? 'manual' : 'auto'),
                      'alloc' => (object) (old('allocations') ?? ($focusBill ? [$focusBill->id => (string) $focusBill->due_amount] : [])),
                  ]))">
                @csrf
                <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">

                <div class="card">
                    <div class="card-header"><h2 class="card-title">Payment</h2></div>
                    <div class="card-body grid gap-4 sm:grid-cols-4">
                        <div><label class="label" for="amount">Amount paid <span class="text-red-500">*</span></label>
                            <input id="amount" name="amount" type="number" step="0.01" min="0.01" x-model="amount" required class="input {{ $errors->has('amount') ? 'input-error' : '' }}" autofocus>
                            @error('amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                        <div><label class="label" for="payment_method_id">Paid by <span class="text-red-500">*</span></label>
                            <select id="payment_method_id" name="payment_method_id" class="input" required>
                                @foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) old('payment_method_id') === $m->id)>{{ $m->name }}{{ $m->requires_reference ? ' (needs a reference)' : '' }}</option>@endforeach
                            </select>
                            @error('payment_method_id')<p class="error-text">{{ $message }}</p>@enderror</div>
                        <x-form.input name="payment_date" label="Date" type="date" :value="now()->toDateString()" required :max="now()->toDateString()" />
                        <x-form.input name="reference_no" label="Reference" help="Cheque, transaction or slip number" />
                        <x-form.input name="notes" label="Note (optional)" class="sm:col-span-4" />
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2 class="card-title">Which bills does it settle?</h2></div>
                    <div class="card-body space-y-3">
                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" name="allocation" value="auto" x-model="mode" class="h-4 w-4 text-brand-600"> Oldest bills first <span class="text-slate-500">(recommended)</span></label>
                            <label class="flex items-center gap-2"><input type="radio" name="allocation" value="manual" x-model="mode" class="h-4 w-4 text-brand-600"> I will choose the bills</label>
                            <label class="flex items-center gap-2"><input type="radio" name="allocation" value="advance" x-model="mode" class="h-4 w-4 text-brand-600"> Keep it as an advance (no bill yet)</label>
                        </div>
                        @error('allocation')<p class="error-text">{{ $message }}</p>@enderror

                        @if($bills->isEmpty())
                            <p class="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-500">This supplier has no unpaid bills. Whatever you pay now is kept as an advance and can be applied to the next bill.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="table">
                                    <thead><tr><th>Bill</th><th>Date</th><th>Their invoice</th><th class="text-right">Total</th><th class="text-right">Still due</th><th class="text-right">This payment pays</th></tr></thead>
                                    <tbody>
                                    <template x-for="b in bills" :key="b.id">
                                        <tr>
                                            <td class="font-mono text-xs font-medium" x-text="b.no"></td>
                                            <td class="whitespace-nowrap" x-text="b.date"></td>
                                            <td x-text="b.invoice || ''"></td>
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
                            <span>Settles bills: <strong x-text="money(plannedTotal)"></strong></span>
                            <span>Kept as an advance: <strong :class="advance > 0 ? 'text-blue-700' : ''" x-text="money(advance)"></strong></span>
                            <span class="text-red-600" x-show="tooMuchOnBill" x-cloak>A bill is paid more than it owes.</span>
                            <span class="text-red-600" x-show="plannedTotal > (parseFloat(amount) || 0) + 0.001" x-cloak>More given to bills than the payment.</span>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <a href="{{ route('supplier-payments.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" :disabled="invalid" data-loading-text="Saving…">Record payment</button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
