@props(['name', 'label' => null, 'options' => [], 'value' => null, 'help' => null, 'required' => false, 'field' => null])
@php($field = $field ?? str_replace('.', '_', $name))
<div {{ $attributes->only('class') }}>
    @if($label)<label for="{{ $field }}" class="label">{{ $label }}@if($required) <span class="text-red-500">*</span>@endif</label>@endif
    <select id="{{ $field }}" name="{{ $field }}" @if($required) required @endif
            {{ $attributes->except('class')->merge(['class' => 'input'.($errors->has($field) ? ' input-error' : '')]) }}>
        @foreach($options as $k => $v)
            <option value="{{ $k }}" @selected((string) old($field, $value) === (string) $k)>{{ $v }}</option>
        @endforeach
    </select>
    @if($help)<p class="help">{{ $help }}</p>@endif
    @error($field)<p class="error-text">{{ $message }}</p>@enderror
</div>
