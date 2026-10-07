@php($editing = $supplier->exists)
<x-layouts.app :title="$editing ? $supplier->name : 'New supplier'">
    <div class="mx-auto grid max-w-4xl gap-5 lg:grid-cols-3">
        <form method="POST" action="{{ $editing ? route('suppliers.update', $supplier) : route('suppliers.store') }}" class="card lg:col-span-2">
            @csrf
            @if($editing) @method('PUT') @endif
            <div class="card-header"><h2 class="card-title">{{ $editing ? $supplier->code : 'Supplier details' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="Name" :value="$supplier->name" required class="sm:col-span-2" />
                <x-form.input name="company" label="Company" :value="$supplier->company" />
                <x-form.input name="contact_person" label="Contact person" :value="$supplier->contact_person" />
                <x-form.input name="phone" label="Phone" :value="$supplier->phone" />
                <x-form.input name="whatsapp" label="WhatsApp" :value="$supplier->whatsapp" />
                <x-form.input name="email" label="Email" type="email" :value="$supplier->email" />
                <x-form.input name="ntn" label="NTN" :value="$supplier->ntn" />
                <x-form.textarea name="address" label="Address" :value="$supplier->address" rows="2" class="sm:col-span-2" />
                <x-form.textarea name="notes" label="Notes" :value="$supplier->notes" rows="2" class="sm:col-span-2" />
                <x-form.checkbox name="is_active" label="Active" :checked="$supplier->is_active" class="sm:col-span-2" />
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <a href="{{ route('suppliers.index') }}" class="btn btn-secondary">{{ $editing ? 'Back' : 'Cancel' }}</a>
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Save' : 'Add supplier' }}</button>
            </div>
        </form>

        @if($editing)
            <div class="space-y-5 self-start">
                <div class="card card-body text-sm text-slate-600">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account</div>
                    <p class="mt-1">Purchases, payments and what you owe this supplier appear here from the next phase (Purchases).</p>
                    <p class="mt-2 text-xs text-slate-500">{{ $supplier->products_count }} product(s) use this supplier as their usual supplier.</p>
                </div>
                <div class="card card-body space-y-2">
                    @can('suppliers.edit')
                        <form method="POST" action="{{ route('suppliers.toggle', $supplier) }}">@csrf
                            <button class="btn btn-secondary w-full" data-no-loading>{{ $supplier->is_active ? 'Deactivate supplier' : 'Activate supplier' }}</button></form>
                    @endcan
                    @can('suppliers.delete')
                        <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}" data-confirm="Delete supplier “{{ $supplier->name }}”?" data-confirm-label="Delete" data-danger="1">
                            @csrf @method('DELETE')<button class="btn btn-secondary w-full text-red-600">Delete supplier</button></form>
                        <p class="help">A supplier that is used cannot be deleted; deactivate it instead.</p>
                    @endcan
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
