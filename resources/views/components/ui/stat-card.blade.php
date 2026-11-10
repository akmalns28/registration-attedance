@props(['title', 'value' => 0, 'description' => null, 'color' => 'primary'])

<div {{ $attributes->class(['card stat-card']) }}>
    <div class="card-body">

        <div class="d-flex align-items-center">

            <div class="subheader">
                {{ $title }}
            </div>

        </div>

        <div class="h1 mb-2 mt-2">
            {{ $value }}
        </div>

        @if ($description)
            <div class="d-flex align-items-center">

                <span class="badge bg-{{ $color }}-lt me-2">
                    {{ $description }}
                </span>

            </div>
        @endif

    </div>
</div>
