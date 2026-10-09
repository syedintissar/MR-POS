@php($editing = $type->exists)
<x-layouts.app :title="$editing ? $type->name : 'New service type'">
    <div class="mx-auto max-w-xl">
        <form method="POST" action="{{ $editing ? route('service.types.update', $type) : route('service.types.store') }}" class="card">
            @csrf
            @if($editing) @method('PUT') @endif
            <div class="card-header"><h2 class="card-title">{{ $editing ? 'Edit service type' : 'New service type' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="Name" :value="$type->name" required class="sm:col-span-2" />
                <x-form.input name="default_charge" label="Suggested charge (Rs.)" type="number" step="0.01" min="0" :value="$type->default_charge" help="Added to a new job when ticked. Leave empty when the price varies." />
                <x-form.input name="sort_order" label="Order in lists" type="number" min="0" :value="$type->sort_order" />
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $type->is_active)) class="h-4 w-4 rounded border-slate-300 text-brand-600"> Offer this service on new jobs</label>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                <a href="{{ route('service.types.index') }}" class="btn btn-secondary">Cancel</a>
                <button class="btn btn-primary" data-loading-text="Saving…">Save</button>
            </div>
        </form>
    </div>
</x-layouts.app>
