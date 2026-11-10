@props([
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => 'Pilih data',
    'required' => false,
])

@php
    $inputId = $attributes->get('id', $name);
    $selectedValue = old($name, $selected);
@endphp

<div class="mb-3">

    @if ($label)
        <label for="{{ $inputId }}" class="form-label {{ $required ? 'required' : '' }}">
            {{ $label }}
        </label>
    @endif

    <select id="{{ $inputId }}" name="{{ $name }}" @required($required)
        {{ $attributes->class(['form-select', 'is-invalid' => $errors->has($name)]) }}>

        <option value="">
            {{ $placeholder }}
        </option>

        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) $selectedValue === (string) $value)>
                {{ $text }}
            </option>
        @endforeach

    </select>

    @error($name)
        <div class="invalid-feedback">
            {{ $message }}
        </div>
    @enderror

</div>
