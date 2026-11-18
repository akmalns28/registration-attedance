@props(['name'])

@php
    $icons = config('ui_icons');
    $geometry = $icons[$name] ?? $icons['help-circle'];
@endphp

<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true" focusable="false" {{ $attributes->class(['icon', 'icon-tabler', 'icon-tabler-'.$name]) }}>
    {!! $geometry !!}
</svg>
