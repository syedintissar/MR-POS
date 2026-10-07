@props(['name', 'label', 'checked' => false, 'help' => null, 'field' => null])
@php($field = $field ?? str_replace('.', '_', $name))
<div {{ $attributes->only('class') }}>
    {{-- The hidden 0 makes an unticked box arrive as "false" instead of being missing. --}}
    <input type="hidden" name="{{ $field }}" value="0">
    <label class="flex cursor-pointer items-start gap-2.5">
        <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $checked) ? true : false)
               {{ $attributes->except('class')->merge(['class' => 'mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500']) }}>
        <span class="text-sm text-slate-700">{{ $label }}@if($help)<span class="help block">{{ $help }}</span>@endif</span>
    </label>
    @error($field)<p class="error-text">{{ $message }}</p>@enderror
</div>
