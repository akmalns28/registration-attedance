@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
])

@php
    $inputId = $attributes->get('id', $name);
@endphp

<div class="mb-3">

    @if ($label)
        <label for="{{ $inputId }}" class="form-label {{ $required ? 'required' : '' }}">
            {{ $label }}
        </label>
    @endif

    <input type="{{ $type }}" id="{{ $inputId }}" name="{{ $name }}" value="{{ old($name, $value) }}"
        placeholder="{{ $placeholder }}" @required($required) @disabled($disabled)
        {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>

    @error($name)
        <div class="invalid-feedback">
            {{ $message }}
        </div>
    @enderror

</div>
