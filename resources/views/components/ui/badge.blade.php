@props([
    'variant' => 'primary',
    'light' => true,
])

@php
    $class = $light ? "badge bg-{$variant}-lt" : "badge bg-{$variant} text-white";
@endphp

<span {{ $attributes->class([$class]) }}>
    {{ $slot }}
</span>
