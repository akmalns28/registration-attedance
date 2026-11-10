@props([
    'variant' => 'info',
    'title' => null,
    'dismissible' => true,
])

<div {{ $attributes->class(['alert', "alert-{$variant}", 'alert-dismissible' => $dismissible]) }}
    role="alert">

    @if ($title)
        <h4 class="alert-title">
            {{ $title }}
        </h4>
    @endif

    <div>
        {{ $slot }}
    </div>

    @if ($dismissible)
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    @endif

</div>
