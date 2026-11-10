@props([
    'type' => 'button',
    'variant' => 'primary',
    'size' => null,
    'href' => null,
    'outline' => false,
    'disabled' => false,
])

@php
    $classes = 'btn ';

    $classes .= $outline ? "btn-outline-{$variant}" : "btn-{$variant}";

    if ($size) {
        $classes .= " btn-{$size}";
    }
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->class([$classes]) }}>
        {{ $slot }}
    </button>
@endif
