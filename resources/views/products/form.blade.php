@php
    $user = auth()->user();
    $editing = $product->exists;
    $canPrice = ! $editing || $user->can('products.edit_price');
    $canOpen = ! $editing && $user->can('inventory.adjust');
    $canCost = $user->can('products.view_cost');
    $unitsJson = $units->mapWithKeys(fn ($u) => [$u->id => (bool) $u->allows_decimal]);
@endphp
<x-layouts.app :title="$editing ? 'Edit product' : 'New product'">
    <form method="POST" action="{{ $editing ? route('products.update', $product) : route('products.store') }}" enctype="multipart/form-data"
          class="mx-auto max-w-5xl space-y-5"
          x-data="{ type: '{{ old('type', $product->type) }}', unit: '{{ old('unit_id', $product->unit_id) }}', decimals: @js($unitsJson), get stock() { return this.type === 'stock' } }">
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
                            :options="['' => '— choose —'] + $categories->flatMap(fn ($c) => [$c->id => $c->name] + $c->children->mapWithKeys(fn ($ch) => [$ch->id => $c->name.' › '.$ch->name])->all())->all()" />
                        <x-form.select name="brand_id" label="Brand" :value="$product->brand_id" :options="['' => '—'] + $brands->pluck('name', 'id')->all()" />
                        <x-form.input name="model" label="Model" :value="$product->model" />
                        <x-form.select name="unit_id" label="Unit" required :value="$product->unit_id" :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->short_name.')'])->all()" x-model="unit"
                                       :disabled="$editing && $hasHistory" :help="$editing && $hasHistory ? 'The unit cannot be changed once the product has stock history.' : null" />
                        @if($editing && $hasHistory)<input type="hidden" name="unit_id" value="{{ $product->unit_id }}">@endif
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
                        @if($canOpen)
                            <x-form.input name="opening_stock" label="Stock you have now" type="number" step="any" min="0"
                                          help="Counted by hand. Leave empty if you have none yet." x-bind:step="decimals[unit] ? '0.001' : '1'" />
                            @if($canCost)<x-form.input name="opening_cost" label="Cost of each (Rs.)" type="number" step="0.01" min="0" help="What one unit cost you." />@endif
                        @elseif($editing)
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
