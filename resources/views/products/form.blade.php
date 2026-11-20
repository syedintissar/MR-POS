@php
    $user = auth()->user();
    $editing = $product->exists;
    $canPrice = ! $editing || $user->can('products.edit_price');
    $canOpen = ! $editing && $user->can('inventory.adjust');
    $canCost = $user->can('products.view_cost');
    $unitsJson = $units->mapWithKeys(fn ($u) => [$u->id => (bool) $u->allows_decimal]);
    $canAddSupplier = $canBuy && $user->can('suppliers.create');
@endphp
<x-layouts.app :title="$editing ? 'Edit product' : 'New product'">
    <script>
        window.productForm = function (init) {
            const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
            return {
                ...init,
                get stock() { return this.type === 'stock'; },
                get total() { return Math.round(num(this.qty) * num(this.cost) * 100) / 100; },
                get paidNow() { return this.payMode === 'credit' ? 0 : (this.payMode === 'full' ? this.total : num(this.payAmount)); },
                get onCredit() { return Math.max(0, Math.round((this.total - this.paidNow) * 100) / 100); },
                get needsRef() { const m = this.methods.find((x) => String(x.id) === String(this.methodId)); return !!(m && m.requires_reference); },
                money(v) { return (v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                async addSupplier() {
                    const f = this.newSup;
                    if (f.name.trim() === '') { f.error = 'Enter the supplier\'s name.'; return; }
                    f.busy = true; f.error = '';
                    try {
                        const r = await fetch(this.quickUrl, {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ name: f.name.trim(), phone: f.phone.trim() || null, company: f.company.trim() || null }),
                        });
                        const d = await r.json().catch(() => ({}));
                        if (!r.ok) { f.error = d.errors ? Object.values(d.errors).flat()[0] : (d.message || 'The supplier could not be saved.'); return; }
                        this.suppliers.push({ id: d.id, label: d.name + (d.company ? ' (' + d.company + ')' : '') });
                        this.supplierId = String(d.id);
                        this.newSup = { open: false, name: '', phone: '', company: '', busy: false, error: '' };
                    } catch { f.error = 'The server could not be reached.'; } finally { f.busy = false; }
                },
            };
        };
    </script>
    <form method="POST" action="{{ $editing ? route('products.update', $product) : route('products.store') }}" enctype="multipart/form-data"
          class="mx-auto max-w-5xl space-y-5"
          x-data="productForm(@js([
              'type' => old('type', $product->type), 'unit' => (string) old('unit_id', $product->unit_id), 'decimals' => $unitsJson,
              'buy' => (bool) old('buy_from_supplier', false), 'qty' => (string) old('opening_stock', ''), 'cost' => (string) old('opening_cost', ''),
              'supplierId' => (string) old('purchase_supplier_id', ''), 'payMode' => old('pay_mode', 'credit'), 'payAmount' => (string) old('pay_amount', ''),
              'methodId' => (string) old('pay_method_id', $payMethods->first()?->id ?? ''), 'methods' => $payMethods->map(fn ($m) => ['id' => $m->id, 'requires_reference' => (bool) $m->requires_reference])->values(),
              'suppliers' => $suppliers->map(fn ($s) => ['id' => $s->id, 'label' => $s->name.($s->company ? ' ('.$s->company.')' : '')])->values(),
              'quickUrl' => $canAddSupplier ? route('suppliers.quick') : null,
              'newSup' => ['open' => false, 'name' => '', 'phone' => '', 'company' => '', 'busy' => false, 'error' => ''],
          ]))">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Product</h2></div>
                    <div class="card-body grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="label">Kind</label>
                            <div class="flex gap-5 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" name="type" value="stock" x-model="type" class="text-brand-600" @disabled($editing && $hasHistory)> Stock item <span class="text-xs text-slate-400">(counted, e.g. a toner)</span></label>
                                <label class="flex items-center gap-2"><input type="radio" name="type" value="service" x-model="type" class="text-brand-600" @disabled($editing && $hasHistory)> Service <span class="text-xs text-slate-400">(no stock, e.g. repair labour)</span></label>
                            </div>
                            @if($editing && $hasHistory)<input type="hidden" name="type" value="{{ $product->type }}"><p class="help">The kind cannot be changed because the product has stock history.</p>@endif
                            @error('type')<p class="error-text">{{ $message }}</p>@enderror
                        </div>
                        <x-form.input name="name" label="Name" :value="$product->name" required class="sm:col-span-2" />
                        <x-form.input name="sku" label="SKU" :value="$product->sku" help="Leave empty to get the next number automatically." />
                        <x-form.input name="barcode" label="Barcode" :value="$product->barcode" help="Scan it into this box. Leave empty if there is none." autocomplete="off" />
                        <x-form.select name="category_id" label="Category" required :value="$product->category_id"
                            :options="$categoryOptions" />
                        <x-form.select name="brand_id" label="Brand" :value="$product->brand_id" :options="['' => '—'] + $brands->pluck('name', 'id')->all()" />
                        <x-form.input name="model" label="Model" :value="$product->model" />
                        <x-form.select name="unit_id" label="Unit" required :value="$product->unit_id" :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->short_name.')'])->all()" x-model="unit"
                                       :disabled="$editing && $hasHistory" :help="$editing && $hasHistory ? 'The unit cannot be changed once the product has stock history.' : null" />
                        @if($editing && $hasHistory)<input type="hidden" name="unit_id" value="{{ $product->unit_id }}">@endif
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 sm:col-span-2" x-show="stock" x-cloak>
                            <div class="mb-2 text-sm font-medium text-slate-800">Bought in a bigger unit? <span class="font-normal text-slate-500">(optional: e.g. toner powder kept in grams but bought by the kilogram)</span></div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-form.select name="purchase_unit_id" label="Bought by the" :value="$product->purchase_unit_id"
                                               :options="['' => '— same as the unit above —'] + $units->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->short_name.')'])->all()" />
                                <x-form.input name="purchase_unit_factor" label="One of those holds how many of the unit above?" type="number" step="0.001" min="0"
                                              :value="$product->purchase_unit_factor !== null ? \App\Support\Decimal::trim((string) $product->purchase_unit_factor) : ''" placeholder="1000" help="Kilogram to gram is 1000. Purchases can then be typed in kilograms; the stock and the cost are kept per gram." />
                            </div>
                        </div>
                        <x-form.textarea name="description" label="Description" :value="$product->description" class="sm:col-span-2" rows="2" />
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2 class="card-title">Prices (Rs.)</h2></div>
                    <fieldset class="card-body grid gap-4 sm:grid-cols-3" @disabled(! $canPrice)>
                        <x-form.input name="sale_price" label="Sale price" type="number" step="0.01" min="0" :value="$product->sale_price" required />
                        <x-form.input name="wholesale_price" label="Wholesale price" type="number" step="0.01" min="0" :value="$product->wholesale_price" />
                        <x-form.input name="min_sale_price" label="Lowest allowed price" type="number" step="0.01" min="0" :value="$product->min_sale_price" help="Cashiers cannot sell below this." />
                    </fieldset>
                    @unless($canPrice)<p class="px-5 pb-4 text-xs text-slate-500">You can see the prices but only a manager can change them.</p>@endunless
                    <div class="card-body grid gap-4 border-t border-slate-100 sm:grid-cols-3">
                        <x-form.input name="tax_rate" label="Tax rate % (optional)" type="number" step="0.01" min="0" max="100" :value="$product->tax_rate" help="Empty uses the default from Settings. Sales tax is currently {{ app(\App\Services\SettingsService::class)->bool('tax.enabled') ? 'ON' : 'OFF' }}." />
                        @if($editing && $canCost && $product->isStockItem())
                            <div>
                                <div class="label">Average cost</div>
                                <div class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ number_format((float) $product->average_cost, 2) }} <span class="text-xs text-slate-400">· last bought at {{ number_format((float) $product->last_purchase_cost, 2) }}</span></div>
                                <p class="help">Worked out by the system from purchases, never typed in.</p>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card" x-show="stock" x-cloak>
                    <div class="card-header"><h2 class="card-title">Stock</h2></div>
                    <div class="card-body grid gap-4 sm:grid-cols-3">
                        @if($canBuy)
                            {{-- the stock came from a supplier: a purchase bill is made and received with the product --}}
                            <label class="flex items-start gap-2.5 rounded-md border border-brand-200 bg-brand-50 p-3 text-sm sm:col-span-3">
                                <input type="hidden" name="buy_from_supplier" value="0">
                                <input type="checkbox" name="buy_from_supplier" value="1" x-model="buy" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600">
                                <span><strong>Bought from a supplier?</strong> <span class="text-slate-600">(optional) Tick to record the stock below as a purchase from a supplier: it goes on their account, paid now by cash / bank / Easypaisa, partly, or on credit.</span></span>
                            </label>
                        @endif
                        @if($canOpen || $canBuy)
                            <div @if(! $canOpen) x-show="buy" x-cloak @endif>
                                <x-form.input name="opening_stock" label="Stock you have now" type="number" step="any" min="0" x-model="qty"
                                              help="Leave empty if you have none yet." x-bind:step="decimals[unit] ? '0.001' : '1'" />
                                <p class="help -mt-1" x-show="buy" x-cloak>The quantity bought from the supplier.</p>
                            </div>
                            @if($canCost)
                                <div @if(! $canOpen) x-show="buy" x-cloak @endif>
                                    <x-form.input name="opening_cost" label="Cost of each (Rs.)" type="number" step="0.01" min="0" x-model="cost" help="What one unit cost you." />
                                </div>
                            @endif
                            @error('buy_from_supplier')<p class="error-text sm:col-span-3">{{ $message }}</p>@enderror
                        @endif
                        @if($canBuy)
                            <div class="space-y-4 rounded-md border border-slate-200 p-4 sm:col-span-3" x-show="buy" x-cloak id="buy-from-supplier">
                                <div class="grid gap-4 sm:grid-cols-3">
                                    <div class="sm:col-span-2">
                                        <label class="label" for="purchase_supplier_id">Supplier <span class="text-red-500">*</span></label>
                                        <div class="flex gap-2">
                                            <select id="purchase_supplier_id" name="purchase_supplier_id" x-model="supplierId" class="input {{ $errors->has('purchase_supplier_id') ? 'input-error' : '' }}">
                                                <option value="">— choose —</option>
                                                <template x-for="s in suppliers" :key="s.id"><option :value="String(s.id)" x-text="s.label" :selected="String(s.id) === supplierId"></option></template>
                                            </select>
                                            @if($canAddSupplier)<button type="button" class="btn btn-secondary whitespace-nowrap" data-no-loading @click="newSup.open = !newSup.open">+ New supplier</button>@endif
                                        </div>
                                        @error('purchase_supplier_id')<p class="error-text">{{ $message }}</p>@enderror
                                    </div>
                                    <x-form.input name="supplier_invoice_no" label="Supplier's bill no. (optional)" />
                                </div>

                                @if($canAddSupplier)
                                    <div class="grid gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-4" x-show="newSup.open" x-cloak id="quick-supplier">
                                        <input type="text" x-model="newSup.name" maxlength="150" placeholder="Supplier name" class="input" aria-label="Supplier name" @keydown.enter.prevent="addSupplier()">
                                        <input type="text" x-model="newSup.phone" maxlength="30" placeholder="Phone" class="input" aria-label="Phone" @keydown.enter.prevent="addSupplier()">
                                        <input type="text" x-model="newSup.company" maxlength="150" placeholder="Company (optional)" class="input" aria-label="Company" @keydown.enter.prevent="addSupplier()">
                                        <button type="button" class="btn btn-primary" data-no-loading :disabled="newSup.busy" @click="addSupplier()" x-text="newSup.busy ? 'Saving…' : 'Add and select'"></button>
                                        <p class="text-sm text-red-600 sm:col-span-4" x-show="newSup.error" x-text="newSup.error"></p>
                                    </div>
                                @endif

                                <div>
                                    <div class="label">Payment</div>
                                    <div class="flex flex-wrap gap-4 text-sm">
                                        <label class="flex items-center gap-2"><input type="radio" name="pay_mode" value="credit" x-model="payMode"> On credit (pay later)</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="pay_mode" value="full" x-model="payMode"> Paid in full now</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="pay_mode" value="part" x-model="payMode"> Paid part now</label>
                                    </div>
                                </div>
                                <input type="hidden" name="pay_amount" :value="payMode === 'credit' ? '' : (payMode === 'full' ? total.toFixed(2) : payAmount)">
                                <div class="grid gap-4 sm:grid-cols-4" x-show="payMode !== 'credit'" x-cloak>
                                    <div x-show="payMode === 'part'"><label class="label" for="pay_amount_part">Paid now (Rs.)</label>
                                        <input id="pay_amount_part" type="number" step="0.01" min="0" x-model="payAmount" class="input {{ $errors->has('pay_amount') ? 'input-error' : '' }}">
                                        @error('pay_amount')<p class="error-text">{{ $message }}</p>@enderror</div>
                                    <div><label class="label" for="pay_method_id">Paid by</label>
                                        <select id="pay_method_id" name="pay_method_id" x-model="methodId" class="input">
                                            @foreach($payMethods as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                                        </select>
                                        @error('pay_method_id')<p class="error-text">{{ $message }}</p>@enderror</div>
                                    <div x-show="needsRef"><x-form.input name="pay_reference" label="Reference (transaction / cheque no.)" /></div>
                                </div>
                                <div class="grid gap-4 sm:grid-cols-4" x-show="onCredit > 0" x-cloak>
                                    <x-form.input name="due_date" label="Pay the rest by (optional)" type="date" :min="now()->toDateString()" help="The dashboard reminds you." />
                                </div>
                                <div class="flex flex-wrap gap-x-6 gap-y-1 rounded-md bg-slate-50 px-3 py-2 text-sm">
                                    <span>Bill total <strong x-text="money(total)"></strong></span>
                                    <span>Paid now <strong x-text="money(paidNow)"></strong></span>
                                    <span>On credit with the supplier <strong class="text-amber-700" x-text="money(onCredit)"></strong></span>
                                    <span class="text-red-600" x-show="paidNow > total + 0.001" x-cloak>Paid now is more than the bill.</span>
                                </div>
                            </div>
                        @endif
                        @if($editing)
                            <div class="sm:col-span-2">
                                <div class="label">Stock now</div>
                                <div class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ \App\Support\Decimal::trim($product->current_stock) }} {{ $product->unit?->short_name }}</div>
                                <p class="help">Stock changes through purchases, sales and <a href="{{ route('adjustments.index') }}">stock adjustments</a>, so there is always a record.</p>
                            </div>
                        @endif
                        <x-form.input name="min_stock_level" label="Warn me when stock is at or below" type="number" step="any" min="0" :value="$product->min_stock_level" help="Empty uses the shop default." />
                        <x-form.input name="rack_location" label="Shelf / rack" :value="$product->rack_location" />
                    </div>
                </div>
            </div>

            <div class="space-y-5">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Picture</h2></div>
                    <div class="card-body space-y-3">
                        @if($editing && $product->image_path)
                            <img src="{{ route('products.image', $product) }}?v={{ $product->updated_at?->timestamp }}" alt="" class="h-40 w-full rounded border border-slate-200 object-contain">
                            <x-form.checkbox name="remove_image" label="Remove the picture" :checked="false" />
                        @endif
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
                        @error('image')<p class="error-text">{{ $message }}</p>@enderror
                        <p class="help">PNG, JPG or WebP up to 2 MB.</p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">More</h2></div>
                    <div class="card-body space-y-4">
                        <x-form.select name="default_supplier_id" label="Usual supplier" :value="$product->default_supplier_id" :options="['' => '—'] + $suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name.($s->company ? ' ('.$s->company.')' : '')])->all()" />
                        <x-form.input name="warranty_months" label="Warranty (months)" type="number" min="0" :value="$product->warranty_months" />
                        <x-form.input name="warranty_terms" label="Warranty terms" :value="$product->warranty_terms" />
                        <x-form.checkbox name="is_active" label="Active (can be sold)" :checked="$product->is_active" />
                    </div>
                </div>
            </div>
        </div>

        <div class="sticky bottom-0 -mx-4 flex justify-end gap-2 border-t border-slate-200 bg-slate-100/95 px-4 py-3 sm:-mx-6 sm:px-6">
            <a href="{{ $editing ? route('products.show', $product) : route('products.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create product' }}</button>
        </div>
    </form>
</x-layouts.app>
