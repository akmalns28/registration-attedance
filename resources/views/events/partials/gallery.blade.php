@php
    $uploadErrors = collect($errors->getMessages())->filter(fn ($messages, $key) =>
        in_array($key, ['title', 'description', 'files'], true) || str_starts_with($key, 'files.'))->flatten();
@endphp

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <h3 class="mb-0">Dokumentasi Event</h3>
    <nav class="btn-list" aria-label="Filter galeri">
        @foreach (['all' => 'Semua', 'image' => 'Foto', 'video' => 'Video'] as $type => $label)
            <a href="{{ route('events.show', ['event' => $event, 'media_type' => $type]) }}#event-gallery"
                class="btn btn-sm {{ $type === $galleryFilter ? 'btn-primary' : 'btn-outline-secondary' }}"
                @if ($type === $galleryFilter) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>

@can('events.update')
    <details class="border rounded p-3 mb-3" @if ($uploadErrors->isNotEmpty()) open @endif>
        <summary class="fw-semibold">Unggah Dokumentasi</summary>
        <form method="POST" action="{{ route('events.gallery.store', $event) }}" enctype="multipart/form-data" data-form class="mt-3">
            @csrf
            @if ($uploadErrors->isNotEmpty())
                <ul class="alert alert-danger list-unstyled" role="alert">
                    @foreach ($uploadErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="gallery-files">Foto dan video</label>
                    <input id="gallery-files" class="form-control" type="file" name="files[]" multiple required
                        accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" aria-describedby="gallery-upload-help">
                    <div class="form-hint" id="gallery-upload-help">
                        Maksimal {{ config('event_assets.gallery_max_files') }} berkas.
                        Foto JPG, PNG, WebP hingga {{ config('event_assets.gallery_image_max_kb') }} KB.
                        Video MP4, WebM, MOV hingga {{ config('event_assets.gallery_video_max_kb') }} KB.
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="gallery-title">Judul (opsional)</label>
                    <input id="gallery-title" class="form-control" name="title" value="{{ old('title') }}" maxlength="160">
                </div>
                <div class="col-12">
                    <label class="form-label" for="gallery-description">Deskripsi (opsional)</label>
                    <textarea id="gallery-description" class="form-control" name="description" rows="2" maxlength="2000">{{ old('description') }}</textarea>
                </div>
            </div>
            <button class="btn btn-primary mt-3" type="submit">Unggah</button>
        </form>
    </details>
@endcan

@if ($gallery->isEmpty())
    <p class="text-secondary py-4 text-center">{{ $galleryFilter === 'all' ? 'Belum ada dokumentasi.' : 'Tidak ada dokumentasi untuk filter ini.' }}</p>
@else
    <div class="event-gallery-grid">
        @foreach ($gallery as $media)
            @php($assetUrl = route('events.gallery.show', [$event, $media]))
            <article class="card h-100">
                @if ($media->media_type === 'image')
                    <a href="{{ $assetUrl }}" target="_blank" rel="noopener">
                        <img src="{{ $assetUrl }}" alt="{{ $media->title ?: $media->original_filename }}" loading="lazy"
                            class="event-gallery-preview w-100 rounded-top">
                    </a>
                @else
                    <video class="event-gallery-preview w-100 rounded-top" controls playsinline preload="none" aria-label="{{ $media->title ?: $media->original_filename }}">
                        <source src="{{ $assetUrl }}" type="{{ $media->mime_type }}">
                    </video>
                @endif
                <div class="card-body p-3">
                    <h4 class="mb-1 text-break">{{ $media->title ?: $media->original_filename }}</h4>
                    @if ($media->description)
                        <p class="small text-secondary text-break mb-1">{!! nl2br(e($media->description)) !!}</p>
                    @endif
                    <div class="dropdown mt-2">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Tindakan</button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="{{ route('events.gallery.download', [$event, $media]) }}">Unduh</a>
                            @can('events.update')
                                <button class="dropdown-item text-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteGalleryMedia"
                                    data-media-delete="{{ route('events.gallery.destroy', [$event, $media]) }}"
                                    data-media-name="{{ $media->title ?: $media->original_filename }}">Hapus</button>
                            @endcan
                        </div>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
    <div class="mt-3">{{ $gallery->links('pagination::simple-bootstrap-5') }}</div>
@endif
