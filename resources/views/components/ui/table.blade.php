@props([
    'responsive' => true,
    'striped' => false,
    'hover' => true,
])

@php
    $classes = ['table', 'table-vcenter', 'card-table'];

    if ($striped) {
        $classes[] = 'table-striped';
    }

    if ($hover) {
        $classes[] = 'table-hover';
    }
@endphp

<div @class(['table-responsive' => $responsive])>

    <table {{ $attributes->class($classes) }}>

        @isset($head)
            <thead>
                {{ $head }}
            </thead>
        @endisset

        <tbody>
            {{ $slot }}
        </tbody>

    </table>

</div>
