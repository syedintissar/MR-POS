@php($editing = $customer->exists)
<x-layouts.app :title="$editing ? $customer->name : 'New customer'">
    <div class="mx-auto max-w-4xl">
        <form method="POST" action="{{ $editing ? route('customers.update', $customer) : route('customers.store') }}" class="card">
            @csrf
            @if($editing) @method('PUT') @endif
            <div class="card-header"><h2 class="card-title">{{ $editing ? $customer->code : 'Customer details' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="Name" :value="$customer->name" required class="sm:col-span-2" />
                <x-form.input name="company" label="Company" :value="$customer->company" />
                <x-form.input name="phone" label="Phone" :value="$customer->phone" />
                <x-form.input name="whatsapp" label="WhatsApp" :value="$customer->whatsapp" />
                <x-form.input name="email" label="Email" type="email" :value="$customer->email" />
                <x-form.textarea name="address" label="Address" :value="$customer->address" rows="2" class="sm:col-span-2" />
                <x-form.textarea name="notes" label="Notes" :value="$customer->notes" rows="2" class="sm:col-span-2" />

                @if(session('duplicate_phone'))
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:col-span-2">
                        <label class="flex items-start gap-2"><input type="checkbox" name="allow_duplicate_phone" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600">
                            <span><strong>Save anyway.</strong> Several customers can share a phone number (a family, a company). Tick this if this really is a different customer.</span></label>
                    </div>
                @endif

                <div class="rounded-md border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                    <div class="mb-2 text-sm font-semibold text-slate-800">Prices and credit</div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div><label class="label" for="price_tier">Price list</label>
                            <select id="price_tier" name="price_tier" class="input" @disabled(! $canTerms)>
                                <option value="retail" @selected(old('price_tier', $customer->price_tier) === 'retail')>Retail</option>
                                <option value="wholesale" @selected(old('price_tier', $customer->price_tier) === 'wholesale')>Wholesale (gets wholesale prices automatically)</option>
                            </select></div>
                        <div class="flex items-end"><label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="allow_credit" value="1" @checked(old('allow_credit', $customer->allow_credit)) @disabled(! $canTerms) class="h-4 w-4 rounded border-slate-300 text-brand-600"> Allow credit (buy now, pay later)</label></div>
                        <div><label class="label" for="credit_limit">Credit limit</label>
                            <input id="credit_limit" name="credit_limit" type="number" step="0.01" min="0" value="{{ old('credit_limit', $customer->credit_limit) }}" placeholder="no limit" class="input" @disabled(! $canTerms)>
                            @error('credit_limit')<p class="error-text">{{ $message }}</p>@enderror</div>
                    </div>
                    @unless($canTerms)<p class="help mt-2">Prices and credit terms are set by a manager. {{ $editing ? '' : 'A new customer starts with retail prices and no credit.' }}</p>@endunless
                </div>

                @if($editing)<x-form.checkbox name="is_active" label="Active" :checked="$customer->is_active" class="sm:col-span-2" />@endif
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <a href="{{ $editing ? route('customers.show', $customer) : route('customers.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Save' : 'Add customer' }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
