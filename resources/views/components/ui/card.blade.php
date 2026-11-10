@props([
    'title' => null,
    'subtitle' => null,
    'footer' => null,
])

<div {{ $attributes->class(['card']) }}>

    @if ($title || $subtitle)
        <div class="card-header">
            <div>
                @if ($title)
                    <h3 class="card-title">
                        {{ $title }}
                    </h3>
                @endif

                @if ($subtitle)
                    <div class="text-secondary small mt-1">
                        {{ $subtitle }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="card-body">
        {{ $slot }}
    </div>

    @if ($footer)
        <div class="card-footer">
            {{ $footer }}
        </div>
    @endif

</div>
