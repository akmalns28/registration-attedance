@props(['title', 'subtitle' => null])

<div {{ $attributes->class(['page-header d-print-none mb-4']) }}>
    <div class="row align-items-center">

        <div class="col">
            <h2 class="page-title">
                {{ $title }}
            </h2>

            @if ($subtitle)
                <div class="text-secondary mt-1">
                    {{ $subtitle }}
                </div>
            @endif
        </div>

        @if (isset($actions))
            <div class="col-auto ms-auto">
                <div class="btn-list">
                    {{ $actions }}
                </div>
            </div>
        @endif

    </div>
</div>
