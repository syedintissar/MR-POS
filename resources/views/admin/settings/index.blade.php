@php($canManage = auth()->user()->can('settings.manage'))
<x-layouts.app title="Settings">
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
            @foreach($tabs as $key => $label)
                <a href="{{ route('settings.index', $key) }}"
                   class="whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium {{ $group === $key ? 'bg-brand-600 text-white hover:text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">{{ $label }}</a>
            @endforeach
        </div>

        @unless($canManage)
            <div class="mb-4 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">You can view these settings but not change them.</div>
        @endunless

        @if($group === 'numbering')
            <form method="POST" action="{{ route('settings.numbering') }}" class="card">
                @csrf @method('PUT')
                <div class="card-header"><h2 class="card-title">Document numbers</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Document</th><th>Prefix</th><th>Digits</th><th>Restart each year</th><th>Next number</th></tr></thead>
                        <tbody>
                        @foreach($sequences as $s)
                            <tr>
                                <td class="font-medium text-slate-800">{{ $s->label }}</td>
                                <td><input name="seq[{{ $s->id }}][prefix]" value="{{ old("seq.{$s->id}.prefix", $s->prefix) }}" class="input w-28" maxlength="20" @disabled(! $canManage)>
                                    @error("seq.{$s->id}.prefix")<p class="error-text">{{ $message }}</p>@enderror</td>
                                <td><input name="seq[{{ $s->id }}][padding]" type="number" min="3" max="10" value="{{ old("seq.{$s->id}.padding", $s->padding) }}" class="input w-20" @disabled(! $canManage)></td>
                                <td><input type="hidden" name="seq[{{ $s->id }}][reset_yearly]" value="0">
                                    <input type="checkbox" name="seq[{{ $s->id }}][reset_yearly]" value="1" @checked(old("seq.{$s->id}.reset_yearly", $s->reset_yearly)) @disabled(! $canManage) class="h-4 w-4 rounded border-slate-300 text-brand-600"></td>
                                <td class="text-slate-500">{{ $s->prefix }}{{ $s->reset_yearly ? now()->format('y').'-' : '' }}{{ str_pad((string) $s->next_number, $s->padding, '0', STR_PAD_LEFT) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-t border-slate-200">
                    <p class="help mb-3">Changing a prefix affects new documents only. Numbers already issued are never changed, and numbers are never reused.</p>
                    @if($canManage)<button type="submit" class="btn btn-primary">Save numbering</button>@endif
                </div>
            </form>
        @elseif($group === 'payment_methods')
            <div class="card overflow-x-auto">
                <div class="card-header"><h2 class="card-title">Payment methods</h2></div>
                <table class="table">
                    <thead><tr><th>Name</th><th>Kind</th><th>Needs a reference</th><th>Order</th><th>Used</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach($methods as $m)
                        <tr>
                            <td colspan="7" class="p-0">
                                <div class="flex flex-wrap items-center gap-2 px-4 py-2.5">
                                    <form method="POST" action="{{ route('payment-methods.update', $m) }}" class="flex flex-1 flex-wrap items-center gap-3">
                                        @csrf @method('PUT')
                                        <input name="name" value="{{ $m->name }}" class="input w-44" maxlength="50" required @disabled(! $canManage)>
                                        @if($m->is_system)
                                            <input type="hidden" name="kind" value="{{ $m->kind }}">
                                            <span class="w-40 text-slate-600">{{ \App\Models\PaymentMethod::KINDS[$m->kind] ?? $m->kind }} <span class="badge badge-gray">system</span></span>
                                        @else
                                            <select name="kind" class="input w-52" @disabled(! $canManage)>@foreach($kinds as $k => $label)<option value="{{ $k }}" @selected($m->kind === $k)>{{ $label }}</option>@endforeach</select>
                                        @endif
                                        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="requires_reference" value="1" @checked($m->requires_reference) @disabled(! $canManage) class="h-4 w-4 rounded border-slate-300 text-brand-600"> reference</label>
                                        <input name="sort_order" type="number" min="0" max="9999" value="{{ $m->sort_order }}" class="input w-20" @disabled(! $canManage)>
                                        <span class="text-xs text-slate-500">{{ $m->supplier_payments_count }} payment(s)</span>
                                        @if($m->is_active)<span class="badge badge-green">On</span>@else<span class="badge badge-gray">Off</span>@endif
                                        @if($canManage)<button class="btn btn-secondary btn-sm">Save</button>@endif
                                    </form>
                                    @if($canManage && ! $m->is_system)
                                        <form method="POST" action="{{ route('payment-methods.toggle', $m) }}">@csrf<button class="btn btn-secondary btn-sm" data-no-loading>{{ $m->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                                        @if($m->supplier_payments_count === 0)
                                            <form method="POST" action="{{ route('payment-methods.destroy', $m) }}" data-confirm="Delete “{{ $m->name }}”?" data-confirm-label="Delete" data-danger="1">@csrf @method('DELETE')<button class="btn btn-secondary btn-sm text-red-600">Delete</button></form>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @foreach(['name', 'kind', 'sort_order'] as $f)@error($f)<p class="error-text px-5 pb-2">{{ $message }}</p>@enderror @endforeach
            </div>

            @if($canManage)
                <form method="POST" action="{{ route('payment-methods.store') }}" class="card mt-4">
                    @csrf
                    <div class="card-header"><h2 class="card-title">Add a payment method</h2></div>
                    <div class="card-body grid gap-4 sm:grid-cols-3">
                        <x-form.input name="name" label="Name" placeholder="e.g. Meezan Bank" required />
                        <x-form.select name="kind" label="Kind" :options="$kinds" value="bank" />
                        <div class="flex items-end"><x-form.checkbox name="requires_reference" label="Ask for a reference number" :checked="false" /></div>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3">
                        <p class="help">Cash and Customer Credit are needed by the system and cannot be removed. A method that has been used can be switched off, never deleted, so old payments keep their record.</p>
                        <button class="btn btn-primary">Add method</button>
                    </div>
                </form>
            @endif
        @else
            <form method="POST" action="{{ route('settings.update', $group) }}" enctype="multipart/form-data" class="card">
                @csrf @method('PUT')
                <div class="card-header"><h2 class="card-title">{{ $tabs[$group] }}</h2></div>
                <fieldset class="card-body grid gap-4 sm:grid-cols-2" @disabled(! $canManage)>
                    @foreach($fields as $key => $def)
                        @php($field = str_replace('.', '_', $key))
                        @switch($def['type'])
                            @case('bool')
                                <x-form.checkbox :name="$key" :label="$def['label']" :checked="$values[$key]" :help="$def['help'] ?? null" class="sm:col-span-2" />
                                @break
                            @case('select')
                                <x-form.select :name="$key" :label="$def['label']" :options="$def['options']" :value="$values[$key]" :help="$def['help'] ?? null" />
                                @break
                            @case('text')
                                <x-form.textarea :name="$key" :label="$def['label']" :value="$values[$key]" :help="$def['help'] ?? null" class="sm:col-span-2" />
                                @break
                            @case('decimal')
                            @case('int')
                                <x-form.input :name="$key" :label="$def['label']" type="number" step="{{ $def['type'] === 'int' ? '1' : '0.01' }}" :value="$values[$key]" :help="$def['help'] ?? null" />
                                @break
                            @default
                                <x-form.input :name="$key" :label="$def['label']" :value="$values[$key]" :help="$def['help'] ?? null" />
                        @endswitch
                    @endforeach

                    @if($group === 'shop')
                        <div class="sm:col-span-2">
                            <label class="label">Logo</label>
                            <div class="flex items-center gap-4">
                                @if($hasLogo)<img src="{{ route('branding.logo') }}?v={{ time() }}" alt="" class="h-16 w-16 rounded border border-slate-200 bg-white object-contain p-1">@endif
                                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="text-sm">
                            </div>
                            @error('logo')<p class="error-text">{{ $message }}</p>@enderror
                            <p class="help">PNG, JPG or WebP, up to 1 MB. Shown on the login page, the sidebar and printed receipts.</p>
                            @if($hasLogo)<div class="mt-2"><x-form.checkbox name="remove_logo" label="Remove the current logo" :checked="false" /></div>@endif
                        </div>
                    @endif

                    @if($group === 'printing')
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 sm:col-span-2">
                            Receipts are printed by the browser to the printer set as the Windows default on each counter PC, so any 80 mm thermal printer
                            with a Windows driver works. The settings above control the layout (paper width, margins, font size, copies and automatic printing).
                            For one-click printing without the print dialog, see the installation guide (Chrome/Edge "kiosk printing").
                        </div>
                    @endif
                </fieldset>
                @if($canManage)
                    <div class="flex justify-end border-t border-slate-200 px-5 py-3">
                        <button type="submit" class="btn btn-primary">Save {{ strtolower($tabs[$group]) }} settings</button>
                    </div>
                @endif
            </form>
        @endif
    </div>
</x-layouts.app>
