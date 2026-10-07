@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'help' => null, 'required' => false, 'field' => null])
@php($field = $field ?? str_replace('.', '_', $name))
<div {{ $attributes->only('class') }}>
    @if($label)<label for="{{ $field }}" class="label">{{ $label }}@if($required) <span class="text-red-500">*</span>@endif</label>@endif
    <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}"
           value="{{ $type === 'password' ? '' : old($field, $value) }}"
           @if($required) required @endif
           {{ $attributes->except('class')->merge(['class' => 'input'.($errors->has($field) ? ' input-error' : '')]) }}>
    @if($help)<p class="help">{{ $help }}</p>@endif
    @error($field)<p class="error-text">{{ $message }}</p>@enderror
</div>
