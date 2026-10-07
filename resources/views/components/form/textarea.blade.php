@props(['name', 'label' => null, 'value' => null, 'help' => null, 'rows' => 3, 'field' => null])
@php($field = $field ?? str_replace('.', '_', $name))
<div {{ $attributes->only('class') }}>
    @if($label)<label for="{{ $field }}" class="label">{{ $label }}</label>@endif
    <textarea id="{{ $field }}" name="{{ $field }}" rows="{{ $rows }}"
              {{ $attributes->except('class')->merge(['class' => 'input'.($errors->has($field) ? ' input-error' : '')]) }}>{{ old($field, $value) }}</textarea>
    @if($help)<p class="help">{{ $help }}</p>@endif
    @error($field)<p class="error-text">{{ $message }}</p>@enderror
</div>
