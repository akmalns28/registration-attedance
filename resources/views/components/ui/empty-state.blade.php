@props([
    'title' => 'Belum Ada Data',
    'description' => 'Data belum tersedia untuk ditampilkan.',
    'image' => null,
    'imageWidth' => 220,
])

<div {{ $attributes->class(['empty py-5']) }}>

    <div class="empty-img mb-4">
        <img src="{{ $image ?? asset('images/empty-state.svg') }}" alt="{{ $title }}" width="{{ $imageWidth }}"
            class="img-fluid" loading="lazy">
    </div>

    <p class="empty-title">
        {{ $title }}
    </p>

    @if ($description)
        <p class="empty-subtitle text-secondary">
            {{ $description }}
        </p>
    @endif

    @isset($action)
        <div class="empty-action">
            {{ $action }}
        </div>
    @endisset

</div>
