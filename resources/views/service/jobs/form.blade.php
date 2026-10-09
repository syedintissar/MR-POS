@php
    use App\Models\ServiceJob;

    $editing = $job->exists;
    $selectedCustomer = old('customer_id', $customer?->id);
@endphp
<x-layouts.app :title="$editing ? 'Edit '.$job->job_no : 'Receive a device'">
    <div class="mx-auto max-w-4xl">
        <form method="POST" action="{{ $editing ? route('service.jobs.update', $job) : route('service.jobs.store') }}" class="space-y-5"
              x-data="{
                  customerId: @js($selectedCustomer ? (string) $selectedCustomer : ''),
                  customerLabel: @js($customer ? $customer->name.($customer->phone ? ' · '.$customer->phone : '') : ''),
                  query: '', results: [], seq: 0,
                  async find() { const q = this.query.trim(); const n = ++this.seq; if (!q) { this.results = []; return; }
                      const r = await fetch(@js(route('service.customers.search')) + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                      if (n === this.seq && r.ok) this.results = await r.json(); },
                  pick(c) { this.customerId = String(c.id); this.customerLabel = c.name + (c.phone ? ' · ' + c.phone : ''); this.results = []; this.query = ''; },
                  clear() { this.customerId = ''; this.customerLabel = ''; },
                  defaultCharges: @js($types->mapWithKeys(fn ($t) => [$t->id => $t->default_charge !== null ? (string) $t->default_charge : null])),
                  typeId: @js((string) old('service_type_id', $job->service_type_id ?? '')),
              }">
            @csrf
            @if($editing) @method('PUT') @endif

            @unless($editing)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Whose device is it?</h2></div>
                    <div class="card-body space-y-3">
                        <input type="hidden" name="customer_id" :value="customerId">
                        <div x-show="customerId" x-cloak class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm">
                            <span>Customer: <strong x-text="customerLabel"></strong></span>
                            <button type="button" class="text-xs text-red-600" data-no-loading @click="clear()">Use a walk-in customer instead</button>
                        </div>
                        <div x-show="!customerId">
                            <label class="label" for="cust-search">Find a customer (name, phone or code), or leave empty for a walk-in customer</label>
                            <div class="relative">
                                <input id="cust-search" type="search" x-model="query" @input.debounce.200ms="find()" autocomplete="off" class="input" placeholder="Start typing…">
                                <ul x-show="results.length" x-cloak class="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                    <template x-for="c in results" :key="c.id">
                                        <li><button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-brand-50" data-no-loading @click="pick(c)"><span class="font-medium" x-text="c.name"></span> <span class="text-slate-500" x-text="c.phone || c.code"></span></button></li>
                                    </template>
                                </ul>
                            </div>
                            <p class="help">A walk-in customer needs a name and phone number below, so the shop can call when the job is ready.</p>
                        </div>
                    </div>
                </div>
            @endunless

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ $editing ? $job->job_no : 'The job' }}</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-2">
                    <x-form.input name="contact_name" label="Contact name" :value="$job->contact_name" help="Who to ask for (a walk-in customer must have one)" />
                    <x-form.input name="contact_phone" label="Contact phone" :value="$job->contact_phone" />

                    <div><label class="label" for="service_type_id">Type of service <span class="text-red-500">*</span></label>
                        <select id="service_type_id" name="service_type_id" class="input" required x-model="typeId">
                            <option value="">Choose…</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}{{ $t->default_charge !== null ? ' · Rs. '.number_format((float) $t->default_charge, 2) : '' }}</option>@endforeach
                        </select>@error('service_type_id')<p class="error-text">{{ $message }}</p>@enderror</div>
                    <div><label class="label" for="device_type">The device is a <span class="text-red-500">*</span></label>
                        <select id="device_type" name="device_type" class="input" required>@foreach(ServiceJob::DEVICE_TYPES as $k => $v)<option value="{{ $k }}" @selected(old('device_type', $job->device_type) === $k)>{{ $v }}</option>@endforeach</select></div>

                    <div><label class="label" for="brand_id">Brand</label>
                        <select id="brand_id" name="brand_id" class="input"><option value="">—</option>@foreach($brands as $b)<option value="{{ $b->id }}" @selected((int) old('brand_id', $job->brand_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                    <x-form.input name="model" label="Model" :value="$job->model" />
                    <x-form.input name="serial_number" label="Serial number" :value="$job->serial_number" />
                    <x-form.input name="cartridge_model" label="Cartridge / toner model" :value="$job->cartridge_model" />

                    <x-form.textarea name="reported_problem" label="Problem reported by the customer" :value="$job->reported_problem" rows="3" required class="sm:col-span-2" />
                    <x-form.input name="accessories_received" label="Accessories received with it" :value="$job->accessories_received" help="Power cable, tray, toner inside…" class="sm:col-span-2" />

                    <x-form.input name="expected_delivery_at" label="Promised for" type="datetime-local" :value="$job->expected_delivery_at?->format('Y-m-d\TH:i')" />
                    <div><label class="label" for="priority">Priority</label>
                        <select id="priority" name="priority" class="input"><option value="normal" @selected(old('priority', $job->priority) === 'normal')>Normal</option><option value="urgent" @selected(old('priority', $job->priority) === 'urgent')>Urgent</option></select></div>

                    @if($canAssign ?? false)
                        <div><label class="label" for="technician_id">Technician</label>
                            <select id="technician_id" name="technician_id" class="input"><option value="">Not assigned yet</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected((int) old('technician_id', $job->technician_id) === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
                    @endif
                    @if($canEstimate ?? false)<x-form.input name="estimate_amount" label="Estimate (Rs.)" type="number" step="0.01" min="0" :value="$job->estimate_amount" />@endif

                    <x-form.textarea name="customer_notes" label="Note for the customer (printed on the slip)" :value="$job->customer_notes" rows="2" class="sm:col-span-2" />
                    @if($canWorkshop ?? false)
                        <x-form.textarea name="diagnosis" label="Diagnosis" :value="$job->diagnosis" rows="2" />
                        <x-form.textarea name="internal_notes" label="Internal notes (never printed)" :value="$job->internal_notes" rows="2" />
                    @endif

                    @unless($editing)
                        <label class="flex items-center gap-2 text-sm sm:col-span-2" x-show="defaultCharges[typeId]" x-cloak>
                            <input type="checkbox" name="add_default_charge" value="1" checked class="h-4 w-4 rounded border-slate-300 text-brand-600">
                            Add this service's usual charge (Rs. <span x-text="defaultCharges[typeId]"></span>) to the job
                        </label>
                    @endunless
                </div>
            </div>

            @if(($canAdvance ?? false) && ! $editing)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Advance (optional)</h2></div>
                    <div class="card-body grid gap-4 sm:grid-cols-3">
                        <x-form.input name="advance_amount" label="Amount taken now (Rs.)" type="number" step="0.01" min="0" />
                        <div><label class="label" for="advance_method">Paid by</label>
                            <select id="advance_method" name="advance_method" class="input">@foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) old('advance_method') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
                        <x-form.input name="advance_reference" label="Reference" help="For bank or wallet payments" />
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <a href="{{ $editing ? route('service.jobs.show', $job) : route('service.jobs.index') }}" class="btn btn-secondary">Cancel</a>
                <button class="btn btn-primary" data-loading-text="Saving…">{{ $editing ? 'Save changes' : 'Receive the device' }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
