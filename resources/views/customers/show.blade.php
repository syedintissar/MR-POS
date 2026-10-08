@php
    use App\Models\CustomerLedgerEntry;
    use App\Support\Money;
@endphp
<x-layouts.app :title="$customer->name">
    <div class="mx-auto max-w-6xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $customer->code }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $customer->name }}@if($customer->company) <span class="text-base font-normal text-slate-500">· {{ $customer->company }}</span>@endif</h2>
                <div class="mt-1 text-sm text-slate-500">
                    {{ collect([$customer->phone, $customer->whatsapp ? 'WhatsApp '.$customer->whatsapp : null, $customer->email])->filter()->implode(' · ') }}
                    @unless($customer->is_active) <span class="badge badge-gray">Inactive</span>@endunless
                    <span class="badge {{ $customer->price_tier === 'wholesale' ? 'badge-blue' : 'badge-gray' }}">{{ $customer->price_tier === 'wholesale' ? 'Wholesale prices' : 'Retail prices' }}</span>
                    <span class="badge {{ $customer->allow_credit ? 'badge-green' : 'badge-gray' }}">{{ $customer->allow_credit ? 'Credit'.($customer->credit_limit !== null ? ' up to '.Money::format($customer->credit_limit) : ', no limit') : 'No credit' }}</span>
                </div>
                @if($customer->address)<div class="mt-1 text-sm text-slate-500">{{ $customer->address }}</div>@endif
                @if($customer->notes)<p class="mt-2 text-sm text-slate-700">{{ $customer->notes }}</p>@endif
            </div>
            <div class="flex flex-wrap gap-2">
                @can('customers.edit')<a href="{{ route('customers.edit', $customer) }}" class="btn btn-secondary btn-sm">Edit</a>@endcan
                @can('pos.access')@if($customer->is_active)<a href="{{ route('pos.index', ['customer' => $customer->id]) }}" class="btn btn-secondary btn-sm">Sell to this customer</a>@endif @endcan
                @if($money)
                    @can('customer_payments.create')<a href="{{ route('customer-payments.create', ['customer' => $customer->id]) }}" class="btn btn-primary btn-sm">Collect a payment</a>@endcan
                    @if($canStatement)
                        <a href="{{ route('customers.statement', array_filter(['customer' => $customer->id, 'from' => $from, 'to' => $to])) }}" target="_blank" class="btn btn-secondary btn-sm">Print statement</a>
                        <a href="{{ route('customers.statement.pdf', array_filter(['customer' => $customer->id, 'from' => $from, 'to' => $to])) }}" class="btn btn-secondary btn-sm">PDF</a>
                    @endif
                @endif
            </div>
        </div>

        @if(! $money)
            <div class="rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">Account details are shown to the people who handle customer payments.</div>
        @else
            @php($balance = (string) $customer->balance)
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card card-body">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account balance</div>
                    <div class="mt-1 text-2xl font-semibold {{ (float) $balance > 0 ? 'text-amber-600' : ((float) $balance < 0 ? 'text-blue-700' : 'text-slate-900') }}">{{ Money::withSymbol(abs((float) $balance)) }}</div>
                    <div class="text-xs text-slate-500">{{ (float) $balance > 0 ? 'owes you' : ((float) $balance < 0 ? 'you hold their credit' : 'nothing owed either way') }}</div>
                </div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Unpaid invoices</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ $open->count() }}</div><div class="text-xs text-slate-500">{{ Money::withSymbol($owed) }} due</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bought in total</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($salesTotal) }}</div></div>
                <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paid in total</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($paidTotal) }}</div>
                    <div class="text-xs text-slate-500">{{ $lastPayment ? 'last on '.$lastPayment->payment_date->format('d M Y') : 'no payment yet' }}</div></div>
            </div>

            @if((float) $advance > 0)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                    <span>This customer has <strong>{{ Money::withSymbol($advance) }}</strong> paid in advance that is not applied to any invoice. They can spend it at the till as “Customer credit”.</span>
                    @if($open->isNotEmpty())@can('customer_payments.create')
                        <form method="POST" action="{{ route('customers.apply-advance', $customer) }}" data-confirm="Apply the advance to {{ $customer->name }}'s oldest unpaid invoices?" data-confirm-label="Apply">@csrf<button class="btn btn-secondary btn-sm">Apply to the oldest invoices</button></form>
                    @endcan @endif
                </div>
            @endif

            @if($open->isNotEmpty())
                <div class="card overflow-x-auto">
                    <div class="card-header"><h2 class="card-title">Invoices still to pay</h2></div>
                    <table class="table">
                        <thead><tr><th>Invoice</th><th>Date</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th class="text-right">Age</th><th></th></tr></thead>
                        <tbody>
                        @foreach($open as $s)
                            <tr>
                                <td class="font-mono text-xs font-medium"><a href="{{ route('sales.show', $s) }}">{{ $s->invoice_no }}</a></td>
                                <td class="whitespace-nowrap">{{ $s->sale_date->format('d M Y') }}</td>
                                <td class="text-right">{{ Money::format($s->grand_total) }}</td><td class="text-right text-slate-500">{{ Money::format($s->paid_amount) }}</td>
                                <td class="text-right font-medium text-amber-700">{{ Money::format($s->due_amount) }}</td>
                                <td class="text-right text-slate-500">{{ (int) $s->sale_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), true) }} d</td>
                                <td class="text-right">@can('customer_payments.create')<a href="{{ route('customer-payments.create', ['customer' => $customer->id, 'sale' => $s->id]) }}" class="btn btn-secondary btn-sm">Pay</a>@endcan</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="grid gap-5 lg:grid-cols-2">
                <div class="card overflow-x-auto">
                    <div class="card-header"><h2 class="card-title">Recent invoices</h2></div>
                    <table class="table"><tbody>
                    @forelse($recentSales as $s)
                        <tr class="{{ $s->isVoided() ? 'text-slate-400' : '' }}">
                            <td class="font-mono text-xs"><a href="{{ route('sales.show', $s) }}">{{ $s->invoice_no }}</a></td>
                            <td class="whitespace-nowrap">{{ $s->sale_date->format('d M Y') }}</td>
                            <td class="text-right {{ $s->isVoided() ? 'line-through' : '' }}">{{ Money::format($s->grand_total) }}</td>
                            <td>@if($s->isVoided())<span class="badge badge-red">Cancelled</span>@elseif($s->payment_status === 'paid')<span class="badge badge-green">Paid</span>@else<span class="badge badge-amber">{{ $s->payment_status === 'partial' ? 'Part paid' : 'Unpaid' }}</span>@endif</td>
                        </tr>
                    @empty<tr><td class="px-4 py-6 text-center text-slate-500">No invoices yet.</td></tr>@endforelse
                    </tbody></table>
                </div>
                <div class="card overflow-x-auto">
                    <div class="card-header"><h2 class="card-title">Recent payments</h2><a href="{{ route('customer-payments.index', ['customer_id' => $customer->id, 'source' => 'all']) }}" class="text-xs">all</a></div>
                    <table class="table"><tbody>
                    @forelse($recentPayments as $p)
                        <tr class="{{ $p->isVoided() ? 'text-slate-400 line-through' : '' }}">
                            <td class="font-mono text-xs"><a href="{{ route('customer-payments.show', $p) }}">{{ $p->receipt_no }}</a></td>
                            <td class="whitespace-nowrap">{{ $p->payment_date->format('d M Y') }}</td><td>{{ $p->method->name }}</td><td class="text-right">{{ Money::format($p->amount) }}</td>
                        </tr>
                    @empty<tr><td class="px-4 py-6 text-center text-slate-500">No payments yet.</td></tr>@endforelse
                    </tbody></table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Account statement</h2></div>
                <form method="GET" class="flex flex-wrap items-end gap-2 border-b border-slate-200 px-5 py-3">
                    <div><label class="label">From</label><input type="date" name="from" value="{{ $from }}" class="input w-40"></div>
                    <div><label class="label">To</label><input type="date" name="to" value="{{ $to }}" class="input w-40"></div>
                    <button class="btn btn-secondary" data-no-loading>Show</button>
                    @if($from || $to)<a href="{{ route('customers.show', $customer) }}" class="btn btn-secondary">Whole history</a>@endif
                </form>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Date</th><th>What</th><th class="text-right">They owe more (+)</th><th class="text-right">They paid / credited (−)</th><th class="text-right">Balance</th></tr></thead>
                        <tbody>
                        @if($from)<tr class="bg-slate-50"><td colspan="4" class="text-slate-600">Brought forward before {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}</td><td class="text-right font-medium">{{ Money::format($statement['opening']) }}</td></tr>@endif
                        @forelse($statement['rows'] as $row)
                            @php($e = $row['entry'])
                            <tr>
                                <td class="whitespace-nowrap text-slate-600">{{ $e->entry_date->format('d M Y') }}</td>
                                <td><span class="font-medium text-slate-800">{{ CustomerLedgerEntry::label($e->entry_type) }}</span>
                                    <div class="text-xs text-slate-500">
                                        @if($e->source_type === 'sale' && auth()->user()->canAny(['sales.view_all', 'sales.view_own']))<a href="{{ route('sales.show', $e->source_id) }}">{{ $e->description }}</a>
                                        @elseif($e->source_type === 'customer_payment')<a href="{{ route('customer-payments.show', $e->source_id) }}">{{ $e->description }}</a>
                                        @else{{ $e->description }}@endif
                                    </div></td>
                                <td class="text-right">{{ (float) $e->debit ? Money::format($e->debit) : '' }}</td>
                                <td class="text-right text-emerald-700">{{ (float) $e->credit ? Money::format($e->credit) : '' }}</td>
                                <td class="text-right font-medium {{ (float) $row['balance'] < 0 ? 'text-blue-700' : '' }}">{{ Money::format($row['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No entries{{ $from || $to ? ' in this period' : ' yet' }}.</td></tr>
                        @endforelse
                        </tbody>
                        <tfoot><tr class="bg-slate-50 font-semibold"><td colspan="2">Totals for the period · closing balance</td><td class="px-4 py-2 text-right">{{ Money::format($statement['debit']) }}</td><td class="px-4 py-2 text-right">{{ Money::format($statement['credit']) }}</td><td class="px-4 py-2 text-right">{{ Money::format($statement['closing']) }}</td></tr></tfoot>
                    </table>
                </div>
                <p class="border-t border-slate-200 px-5 py-2 text-xs text-slate-500">A positive balance is what the customer owes you; a negative balance (blue) is their money that you hold.</p>
            </div>

            @can('customers.adjust_balance')
                <details class="card card-body" @if($errors->hasAny(['type', 'direction', 'amount', 'reason', 'entry_date'])) open @endif>
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800">Set an opening balance or correct the account (administrator only)</summary>
                    <form method="POST" action="{{ route('customers.adjust-balance', $customer) }}" class="mt-4 grid gap-4 sm:grid-cols-4"
                          data-confirm="Post this entry to {{ $customer->name }}'s account? It cannot be edited afterwards, only offset by another entry." data-confirm-label="Post entry">
                        @csrf
                        <div><label class="label" for="type">Type</label>
                            <select id="type" name="type" class="input">
                                <option value="opening_balance" @selected(old('type') === 'opening_balance')>Opening balance (once)</option>
                                <option value="balance_adjustment" @selected(old('type', 'balance_adjustment') === 'balance_adjustment')>Correction</option>
                            </select></div>
                        <div><label class="label" for="direction">Who owes whom</label>
                            <select id="direction" name="direction" class="input">
                                <option value="customer_owes" @selected(old('direction') === 'customer_owes')>The customer owes us</option>
                                <option value="shop_owes" @selected(old('direction') === 'shop_owes')>We hold the customer's money</option>
                            </select></div>
                        <div><label class="label" for="amount">Amount</label><input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required class="input">
                            @error('amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                        <x-form.input name="entry_date" label="Date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" />
                        <x-form.input name="reason" label="Reason (required)" required class="sm:col-span-3" help="For example: balance carried over from the paper register." />
                        <div class="flex items-end"><button class="btn btn-primary">Post entry</button></div>
                    </form>
                </details>
            @endcan
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('customers.index') }}" class="text-sm">← All customers</a>
            <div class="flex gap-2">
                @can('customers.edit')
                    <form method="POST" action="{{ route('customers.toggle', $customer) }}">@csrf<button class="btn btn-secondary btn-sm" data-no-loading>{{ $customer->is_active ? 'Deactivate customer' : 'Activate customer' }}</button></form>
                @endcan
                @can('customers.delete')
                    <form method="POST" action="{{ route('customers.destroy', $customer) }}" data-confirm="Delete customer “{{ $customer->name }}”?" data-confirm-label="Delete" data-danger="1">@csrf @method('DELETE')<button class="btn btn-secondary btn-sm text-red-600">Delete</button></form>
                @endcan
            </div>
        </div>
    </div>
</x-layouts.app>
