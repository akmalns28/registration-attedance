@props(['id', 'label', 'options'])

<div class="col-12">
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    <select id="{{ $id }}" data-filter-label="{{ $label }}" {{ $attributes->class(['form-select']) }}>
        @foreach ($options as $value => $text)
            <option value="{{ $value }}">{{ $text }}</option>
        @endforeach
    </select>
</div>
