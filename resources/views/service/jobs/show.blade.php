@php
    use App\Models\ServiceJob;
    use App\Support\Decimal;
    use App\Support\Money;

    $badge = match ($job->status) {
        ServiceJob::COMPLETED => 'badge-green', ServiceJob::DELIVERED => 'badge-gray', ServiceJob::CANCELLED => 'badge-red',
        ServiceJob::WAITING_APPROVAL, ServiceJob::WAITING_PARTS => 'badge-amber', ServiceJob::IN_PROGRESS, ServiceJob::APPROVED => 'badge-blue',
        default => 'badge-gray',
    };
    $named = ! $job->customer->is_walk_in;
    $paidTowards = $job->isCancelled() ? '0.00' : (string) $job->paid_amount;
    $methodsForDelivery = $methods->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'ref' => (bool) $m->requires_reference])->values();
    if ($named && (float) $advanceLeft > 0 && $creditMethodId) {
        $methodsForDelivery = $methodsForDelivery->push(['id' => $creditMethodId, 'name' => 'Customer credit ('.Money::format($advanceLeft).' available)', 'ref' => false]);
    }
    $cashId = optional($methods->firstWhere('kind', 'cash'))->id;
@endphp
<x-layouts.app :title="$job->job_no">
    <div class="mx-auto max-w-6xl space-y-5">

        {{-- what just happened: the slip / invoice to print, the change to give --}}
        @if($change)<div class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-lg font-bold text-emerald-800" role="status">Change to give: {{ Money::withSymbol($change) }}</div>@endif
        @if($slipUrl && ! $autoSlip)<div class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm">The job slip is ready: <a href="{{ $slipUrl }}" target="_blank" data-no-loading class="font-semibold">print it</a>.</div>@endif
        @if($invoiceUrl && ! $autoInvoice)<div class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm">The invoice is ready: <a href="{{ $invoiceUrl }}" target="_blank" data-no-loading class="font-semibold">print it</a>.</div>@endif
        @if($receiptUrl)<div class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm">The receipt is ready: <a href="{{ $receiptUrl }}" target="_blank" data-no-loading class="font-semibold">print it</a>.</div>@endif

        {{-- header --}}
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-mono text-xl font-semibold text-slate-900">{{ $job->job_no }}</h2>
                    <span class="badge {{ $badge }} text-sm">{{ $job->statusLabel() }}</span>
                    @if($job->priority === 'urgent')<span class="badge badge-red">urgent</span>@endif
                    @if($job->isOverdue())<span class="badge badge-red">overdue since {{ $job->expected_delivery_at->format('d M') }}</span>@endif
                    @if($job->invoice_no)<span class="badge badge-gray font-mono">{{ $job->invoice_no }}</span>@endif
                </div>
                <div class="mt-1 text-sm text-slate-500">{{ $job->serviceType->name }} · received {{ $job->received_at->format('d M Y, H:i') }} by {{ $job->creator?->name ?? 'system' }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($can['edit'])<a href="{{ route('service.jobs.edit', $job) }}" class="btn btn-secondary btn-sm">Edit details</a>@endif
                @if($can['reprint'])
                    <a href="{{ route('service.jobs.slip.reprint', [$job, 'thermal']) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Slip (80 mm)</a>
                    <a href="{{ route('service.jobs.slip.reprint', [$job, 'a4']) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Slip (A4)</a>
                    @if($job->isDelivered())
                        <a href="{{ route('service.jobs.invoice.reprint', [$job, 'thermal']) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Invoice (80 mm)</a>
                        <a href="{{ route('service.jobs.invoice.reprint', [$job, 'a4']) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Invoice (A4)</a>
                        <a href="{{ route('service.jobs.invoice.pdf', $job) }}" class="btn btn-secondary btn-sm" data-no-loading>PDF</a>
                    @endif
                @endif
                @if($whatsapp)<a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" data-no-loading title="Opens WhatsApp with a ready message (needs internet at that moment)">WhatsApp</a>@endif
            </div>
        </div>

        @if($job->isCancelled())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Cancelled by {{ $job->canceller?->name }} on {{ $job->cancelled_at->format('d M Y, H:i') }}: “{{ $job->cancel_reason }}”.</div>
        @endif

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">

                {{-- the device and the customer --}}
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Customer and device</h2></div>
                    <dl class="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Customer</dt>
                            <dd>@if($named)@can('customers.view')<a href="{{ route('customers.show', $job->customer_id) }}">{{ $job->customer->name }}</a>@else{{ $job->customer->name }}@endcan @else Walk-in customer @endif</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Contact</dt><dd>{{ $job->contact_name ?: '—' }}{{ $job->contact_phone ? ' · '.$job->contact_phone : '' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Device</dt><dd>{{ ServiceJob::DEVICE_TYPES[$job->device_type] ?? $job->device_type }}{{ $job->brand ? ' · '.$job->brand->name : '' }}{{ $job->model ? ' · '.$job->model : '' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Serial / cartridge model</dt><dd>{{ $job->serial_number ?: '—' }}{{ $job->cartridge_model ? ' · '.$job->cartridge_model : '' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Problem reported</dt><dd class="whitespace-pre-line">{{ $job->reported_problem }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Diagnosis</dt><dd class="whitespace-pre-line">{{ $job->diagnosis ?: '—' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Accessories received</dt><dd>{{ $job->accessories_received ?: '—' }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500">Promised for</dt><dd class="{{ $job->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ $job->expected_delivery_at?->format('d M Y, H:i') ?? '—' }}</dd></div>
                        @if($job->approved_at)<div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Customer approval</dt><dd>{{ ServiceJob::APPROVAL_METHODS[$job->approval_method] ?? $job->approval_method }} on {{ $job->approved_at->format('d M Y, H:i') }}, recorded by {{ $job->approver?->name }}@if($job->approved_amount !== null) for {{ Money::withSymbol($job->approved_amount) }}@endif</dd></div>@endif
                        @if($job->customer_notes)<div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Note for the customer</dt><dd class="whitespace-pre-line">{{ $job->customer_notes }}</dd></div>@endif
                        @if($job->internal_notes)<div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Internal notes</dt><dd class="whitespace-pre-line">{{ $job->internal_notes }}</dd></div>@endif
                    </dl>
                </div>

                {{-- parts and charges --}}
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Parts and charges</h2></div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>What</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Discount</th><th class="text-right">Total</th>@if($can['cost'])<th class="text-right" title="What the part cost the shop (internal)">Cost</th>@endif<th></th></tr></thead>
                            <tbody>
                            @forelse($active as $i)
                                <tr>
                                    <td>{{ $i->description }} @if($i->isPart())<span class="badge badge-gray">part</span>@endif</td>
                                    <td class="text-right">{{ Decimal::trim((string) $i->quantity) }}</td>
                                    <td class="text-right">{{ Money::format($i->unit_price) }}</td>
                                    <td class="text-right text-slate-500">{{ (float) $i->discount_amount ? Money::format($i->discount_amount) : '' }}</td>
                                    <td class="text-right font-medium">{{ Money::format($i->line_total) }}</td>
                                    @if($can['cost'])<td class="text-right text-slate-500">{{ $i->isPart() ? Money::format(Decimal::mul((string) $i->quantity, (string) $i->unit_cost, 2)) : '' }}</td>@endif
                                    <td class="text-right">
                                        @if(($i->isPart() ? $can['parts'] : $can['charges']))
                                            <form method="POST" action="{{ route('service.jobs.lines.remove', [$job, $i]) }}" class="inline-flex items-center gap-1" @if($i->isPart()) x-data="{ d: 'returned_to_stock' }" @endif
                                                  data-confirm="Take &quot;{{ $i->description }}&quot; off this job?" data-confirm-label="Take off" data-danger="1">
                                                @csrf
                                                @if($i->isPart())
                                                    <select name="disposition" x-model="d" class="h-7 rounded border border-slate-300 bg-white px-1 text-xs" aria-label="What happens to the part"><option value="returned_to_stock">back to stock</option><option value="written_off">write off</option></select>
                                                    <input type="text" name="reason" x-show="d === 'written_off'" x-cloak placeholder="Why?" class="h-7 w-28 rounded border border-slate-300 px-1 text-xs">
                                                @endif
                                                <button class="btn btn-secondary btn-sm text-red-600">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $can['cost'] ? 7 : 6 }}" class="px-4 py-6 text-center text-slate-500">Nothing on the job yet.</td></tr>
                            @endforelse
                            @foreach($removed as $i)
                                <tr class="text-slate-400">
                                    <td colspan="{{ $can['cost'] ? 6 : 5 }}" class="line-through">{{ $i->description }} × {{ Decimal::trim((string) $i->quantity) }}</td>
                                    <td class="text-right text-xs">{{ $i->removal_disposition ? str_replace('_', ' ', $i->removal_disposition) : 'removed' }}{{ $i->removal_reason ? ': '.$i->removal_reason : '' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-50"><td colspan="{{ $can['cost'] ? 4 : 3 }}" class="px-4 py-2 text-right text-slate-500">Charges {{ Money::format($job->service_charges) }} · Parts {{ Money::format($job->parts_amount) }}@if((float) $job->discount_amount) · Discount − {{ Money::format($job->discount_amount) }}@endif @if((float) $job->tax_amount) · Tax {{ Money::format($job->tax_amount) }}@endif</td>
                                    <td class="px-4 py-2 text-right text-base font-semibold">{{ Money::format($job->grand_total) }}</td><td colspan="{{ $can['cost'] ? 2 : 1 }}"></td></tr>
                            </tfoot>
                        </table>
                    </div>

                    @if($can['parts'] && $job->acceptsParts())
                        <form method="POST" action="{{ route('service.jobs.parts.add', $job) }}" class="border-t border-slate-100 p-5"
                              x-data="{ q: '', results: [], seq: 0, pick: null,
                                        async find() { const s = ++this.seq; const t = this.q.trim(); if (!t) { this.results = []; return; }
                                            const r = await fetch(@js(route('service.products.search')) + '?q=' + encodeURIComponent(t), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                                            if (s === this.seq && r.ok) this.results = await r.json(); },
                                        choose(p) { this.pick = p; this.results = []; this.q = ''; } }">
                            @csrf
                            <h3 class="mb-2 text-sm font-semibold text-slate-800">Add a part from stock</h3>
                            <input type="hidden" name="product_id" :value="pick ? pick.id : ''">
                            <div class="grid gap-3 sm:grid-cols-12">
                                <div class="relative sm:col-span-6">
                                    <div x-show="pick" x-cloak class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"><span><strong x-text="pick && pick.name"></strong> <span class="text-slate-500" x-text="pick && ('in stock ' + pick.stock)"></span></span><button type="button" class="text-xs text-red-600" data-no-loading @click="pick = null">change</button></div>
                                    <div x-show="!pick">
                                        <input type="search" x-model="q" @input.debounce.200ms="find()" autocomplete="off" placeholder="Search the stock: name, SKU or barcode" class="input" aria-label="Search the stock">
                                        <ul x-show="results.length" x-cloak class="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                            <template x-for="p in results" :key="p.id"><li><button type="button" data-no-loading class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-brand-50" @click="choose(p)"><span x-text="p.name"></span><span class="text-xs text-slate-500" x-text="'stock ' + p.stock + ' · ' + p.price"></span></button></li></template>
                                        </ul>
                                    </div>
                                </div>
                                <div class="sm:col-span-2"><input type="number" name="quantity" step="0.001" min="0.001" value="1" class="input" aria-label="Quantity" placeholder="Qty"></div>
                                @if($can['charges'])
                                    <div class="sm:col-span-2"><input type="number" name="unit_price" step="0.01" min="0" class="input" aria-label="Price (leave empty for the list price)" placeholder="Price" :placeholder="pick ? pick.price : 'Price'"></div>
                                    <div class="sm:col-span-2"><input type="number" name="discount_amount" step="0.01" min="0" class="input" aria-label="Discount" placeholder="Discount"></div>
                                @endif
                            </div>
                            <div class="mt-3"><button class="btn btn-primary btn-sm" :disabled="!pick" data-loading-text="Adding…">Add part (takes it out of stock now)</button></div>
                        </form>
                    @elseif($can['parts'] && ! $job->isTerminal())
                        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">Parts can be added once the job is being diagnosed.</p>
                    @endif

                    @if($can['charges'])
                        <div class="grid gap-5 border-t border-slate-100 p-5 md:grid-cols-2">
                            <form method="POST" action="{{ route('service.jobs.charges.add', $job) }}" class="space-y-2">
                                @csrf
                                <h3 class="text-sm font-semibold text-slate-800">Add a charge</h3>
                                <input type="text" name="description" required maxlength="255" placeholder="Labour, toner refill, call-out…" class="input" aria-label="Description">
                                <div class="grid grid-cols-3 gap-2">
                                    <input type="number" name="quantity" step="0.001" min="0.001" value="1" class="input" aria-label="Quantity">
                                    <input type="number" name="unit_price" step="0.01" min="0" required placeholder="Price" class="input" aria-label="Price">
                                    <input type="number" name="discount_amount" step="0.01" min="0" placeholder="Discount" class="input" aria-label="Discount">
                                </div>
                                <button class="btn btn-secondary btn-sm" data-loading-text="Adding…">Add charge</button>
                            </form>
                            <form method="POST" action="{{ route('service.jobs.pricing', $job) }}" class="space-y-2">
                                @csrf
                                <h3 class="text-sm font-semibold text-slate-800">Estimate and discount</h3>
                                <div class="grid grid-cols-2 gap-2">
                                    <div><label class="label" for="estimate_amount">Estimate given (Rs.)</label><input id="estimate_amount" type="number" name="estimate_amount" step="0.01" min="0" value="{{ old('estimate_amount', $job->estimate_amount) }}" class="input"></div>
                                    <div><label class="label" for="discount_amount_job">Discount on the job (Rs.)</label><input id="discount_amount_job" type="number" name="discount_amount" step="0.01" min="0" value="{{ old('discount_amount', (float) $job->discount_amount ? $job->discount_amount : '') }}" class="input"></div>
                                </div>
                                <button class="btn btn-secondary btn-sm" data-loading-text="Saving…">Save</button>
                            </form>
                        </div>
                    @endif
                </div>

                {{-- history --}}
                <div class="card">
                    <div class="card-header"><h2 class="card-title">History</h2></div>
                    <ol class="divide-y divide-slate-100 text-sm">
                        @foreach($job->history->reverse() as $h)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5">
                                <span>@if($h->from_status === $h->to_status)<strong>{{ $h->note }}</strong>@else<strong>{{ ServiceJob::STATUSES[$h->to_status] ?? $h->to_status }}</strong>@if($h->note) <span class="text-slate-600">· {{ $h->note }}</span>@endif @endif</span>
                                <span class="text-xs text-slate-500">{{ $h->user?->name ?? 'system' }} · {{ $h->changed_at->format('d M Y, H:i') }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            <div class="space-y-5">

                {{-- status --}}
                @if($next->isNotEmpty())
                    <form method="POST" action="{{ route('service.jobs.status', $job) }}" class="card card-body space-y-3" x-data="{ to: @js($next->first()) }">
                        @csrf
                        <h3 class="card-title">Move the job on</h3>
                        <select name="to" x-model="to" class="input" aria-label="New status">@foreach($next as $s)<option value="{{ $s }}">{{ ServiceJob::STATUSES[$s] }}</option>@endforeach</select>
                        <div x-show="to === 'approved'" x-cloak>
                            <label class="label" for="approval_method">How did the customer approve?</label>
                            <select id="approval_method" name="approval_method" class="input"><option value="">Choose…</option>@foreach(ServiceJob::APPROVAL_METHODS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                        </div>
                        <input type="text" name="note" maxlength="255" class="input" :placeholder="{{ $job->status === ServiceJob::COMPLETED ? "'Why is it being reopened? (required)'" : "'Note (optional)'" }}" aria-label="Note">
                        <button class="btn btn-primary w-full" data-loading-text="Saving…">Change status</button>
                    </form>
                @endif

                {{-- technician --}}
                @if($can['assign'])
                    <form method="POST" action="{{ route('service.jobs.assign', $job) }}" class="card card-body space-y-2">
                        @csrf
                        <h3 class="card-title">Technician</h3>
                        <select name="technician_id" class="input" aria-label="Technician"><option value="">Not assigned</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected($job->technician_id === $t->id)>{{ $t->name }}</option>@endforeach</select>
                        <button class="btn btn-secondary btn-sm" data-loading-text="Saving…">Assign</button>
                    </form>
                @else
                    <div class="card card-body text-sm"><div class="text-xs font-semibold uppercase text-slate-500">Technician</div>{{ $job->technician?->name ?? 'Not assigned' }}</div>
                @endif

                {{-- money --}}
                @if($can['money'])
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Money</h2></div>
                        <dl class="space-y-1 p-5 text-sm">
                            <div class="flex justify-between"><dt>Job total</dt><dd class="font-semibold">{{ Money::withSymbol($job->grand_total) }}</dd></div>
                            <div class="flex justify-between text-slate-600"><dt>Paid</dt><dd>{{ Money::withSymbol($paidTowards) }}</dd></div>
                            <div class="flex justify-between {{ (float) $job->due_amount > 0 ? 'font-semibold text-amber-700' : 'text-slate-600' }}"><dt>{{ $job->isDelivered() ? 'Left on account' : 'Still to pay' }}</dt><dd>{{ Money::withSymbol($job->due_amount) }}</dd></div>
                            @if($job->estimate_amount !== null)<div class="flex justify-between text-slate-500"><dt>Estimate</dt><dd>{{ Money::withSymbol($job->estimate_amount) }}</dd></div>@endif
                            @if($can['cost'])<div class="flex justify-between text-slate-500"><dt>Parts cost (internal)</dt><dd>{{ Money::withSymbol($job->parts_cost) }}</dd></div>@endif
                        </dl>
                        @if($job->allocations->isNotEmpty() && ! $job->isCancelled())
                            <ul class="divide-y divide-slate-100 border-t border-slate-100 text-xs">
                                @foreach($job->allocations as $a)
                                    <li class="flex justify-between px-5 py-2"><span>@can('customer_payments.view')<a href="{{ route('customer-payments.show', $a->customer_payment_id) }}">{{ $a->payment->receipt_no }}</a>@else{{ $a->payment->receipt_no }}@endcan · {{ $a->payment->method->name }}@if($a->payment->status === 'voided') <span class="badge badge-red">cancelled</span>@endif</span><span>{{ Money::format($a->amount) }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                @if($can['advance'])
                    <form method="POST" action="{{ route('service.jobs.advance', $job) }}" class="card card-body space-y-2">
                        @csrf
                        <h3 class="card-title">Take an advance</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="Amount" class="input" aria-label="Amount">
                            <select name="payment_method_id" class="input" aria-label="Paid by">@foreach($methods as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                        </div>
                        <input type="text" name="reference_no" maxlength="100" placeholder="Reference (bank / wallet)" class="input" aria-label="Reference">
                        <button class="btn btn-secondary btn-sm" data-loading-text="Saving…">Record advance</button>
                    </form>
                @endif

                {{-- deliver --}}
                @if($can['deliver'])
                    <form method="POST" action="{{ route('service.jobs.deliver', $job) }}" class="card card-body space-y-3" id="deliver-form" data-confirm="Deliver {{ $job->job_no }} and issue the invoice? The job will be locked." data-confirm-label="Deliver"
                          x-data="{ methods: @js($methodsForDelivery), rows: [{ method: @js($cashId), amount: @js((string) $job->due_amount), reference: '' }],
                                    add() { this.rows.push({ method: this.methods[0]?.id ?? null, amount: '', reference: '' }) },
                                    needsRef(r) { return this.methods.find(m => m.id == r.method)?.ref } }">
                        @csrf
                        <h3 class="card-title">Deliver and invoice</h3>
                        <p class="text-sm text-slate-600">Total <strong>{{ Money::withSymbol($job->grand_total) }}</strong>@if((float) $job->paid_amount > 0), already paid {{ Money::withSymbol($job->paid_amount) }}@endif. Type what the customer pays now{{ $named ? '; anything unpaid stays on their account' : ' (the walk-in customer pays in full)' }}.</p>
                        <template x-for="(r, i) in rows" :key="i">
                            <div class="grid grid-cols-12 items-center gap-1.5">
                                <select :name="'payments[' + i + '][payment_method_id]'" x-model="r.method" class="input col-span-5 h-9 py-1" aria-label="Payment method"><template x-for="m in methods" :key="m.id"><option :value="m.id" x-text="m.name"></option></template></select>
                                <input type="number" step="0.01" min="0" :name="'payments[' + i + '][amount]'" x-model="r.amount" class="input col-span-4 h-9 py-1 text-right" aria-label="Amount">
                                <button type="button" class="col-span-3 text-xs text-red-600" x-show="rows.length > 1" data-no-loading @click="rows.splice(i, 1)">remove</button>
                                <input type="text" x-show="needsRef(r)" x-cloak :name="'payments[' + i + '][reference_no]'" x-model="r.reference" placeholder="Reference" class="input col-span-12 h-9 py-1 text-sm">
                            </div>
                        </template>
                        <button type="button" class="text-left text-sm" data-no-loading @click="add()">+ Add another payment method</button>
                        <button class="btn btn-primary w-full" data-loading-text="Saving…">Deliver and invoice</button>
                    </form>
                @endif

                {{-- cancel --}}
                @if($can['cancel'])
                    <details class="card card-body">
                        <summary class="cursor-pointer text-sm font-semibold text-red-700">Cancel this job…</summary>
                        <form method="POST" action="{{ route('service.jobs.cancel', $job) }}" class="mt-3 space-y-3" x-data="{ adv: 'credit' }" data-confirm="Cancel {{ $job->job_no }}? Nothing is deleted; parts go back to stock as chosen." data-confirm-label="Cancel the job" data-danger="1">
                            @csrf
                            <input type="text" name="reason" required maxlength="255" placeholder="Why is the job cancelled?" class="input" aria-label="Reason">
                            @foreach($active->where('line_type', 'part') as $p)
                                <div class="flex items-center justify-between gap-2 text-sm"><span>{{ $p->description }} × {{ Decimal::trim((string) $p->quantity) }}</span>
                                    <select name="parts[{{ $p->id }}]" class="h-8 rounded border border-slate-300 bg-white px-1 text-xs" aria-label="What happens to {{ $p->description }}"><option value="returned_to_stock">back to stock</option><option value="written_off">written off</option></select></div>
                            @endforeach
                            @if((float) $job->paid_amount > 0 && $can['money'])
                                <div class="rounded-md bg-slate-50 p-3 text-sm">
                                    <div class="mb-1 font-medium">The customer paid {{ Money::withSymbol($job->paid_amount) }} in advance:</div>
                                    @if($named)<label class="flex items-center gap-2"><input type="radio" name="advance" value="credit" x-model="adv" checked> keep it as their credit</label>@endif
                                    <label class="flex items-center gap-2"><input type="radio" name="advance" value="refund" x-model="adv" @checked(! $named)> hand it back</label>
                                    <select name="refund_method_id" x-show="adv === 'refund'" x-cloak class="input mt-2" aria-label="Hand it back with">@foreach($methods as $m)<option value="{{ $m->id }}" @selected($m->id === $cashId)>{{ $m->name }}</option>@endforeach</select>
                                </div>
                            @endif
                            <button class="btn btn-danger btn-sm" data-loading-text="Saving…">Cancel the job</button>
                        </form>
                    </details>
                @endif
            </div>
        </div>
    </div>

    @if($autoSlip && $slipUrl)<iframe src="{{ $slipUrl }}" title="Job slip" aria-hidden="true" style="position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden"></iframe>@endif
    @if($autoInvoice && $invoiceUrl)<iframe src="{{ $invoiceUrl }}" title="Invoice" aria-hidden="true" style="position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden"></iframe>@endif
</x-layouts.app>
