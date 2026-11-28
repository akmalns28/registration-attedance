@php
    $posterLimit = \App\Services\EventPosterService::uploadLimitLabel();
    $posterDays = $event->days ?? collect();
    $uploadAssignments = collect(old('poster_assignments'))->filter(fn ($assignment, $key) =>
        str_starts_with((string) $key, 'upload:') && is_string($assignment['upload_reference'] ?? null))->values();
@endphp
<section class="card mt-3" data-poster-editor data-limit="{{ config('event_assets.poster_max_count') }}"
    data-file-limit="{{ config('event_assets.poster_max_kb') * 1024 }}" data-file-limit-label="{{ $posterLimit }}"
    data-post-limit="{{ config('event_assets.post_max_bytes') }}" data-poster-upload-assignments="{{ $uploadAssignments->toJson() }}">
    <div class="card-header"><h3 class="card-title">Poster Event</h3></div>
    <div class="card-body">
        <label for="event-posters" class="form-label">Tambah poster</label>
        <input id="event-posters" class="form-control" type="file" name="posters[]" multiple
            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="event-posters-help">
        <div class="form-hint" id="event-posters-help">
            JPG, JPEG, PNG, atau WebP. Maksimal {{ config('event_assets.poster_max_count') }} poster,
            {{ $posterLimit }} per berkas dan {{ config('event_assets.post_max_bytes') / 1048576 }} MB per permintaan.
            Sisi terpanjang maksimal {{ number_format(config('event_assets.poster_max_side'), 0, ',', '.') }} piksel,
            dengan luas maksimal {{ config('event_assets.poster_max_pixels') / 1000000 }} megapiksel.
        </div>
        <p class="form-hint mt-2 mb-0">Poster utama opsional. Simpan perubahan setelah mengatur poster.</p>
        @error('poster_primary')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
        @foreach (array_merge($errors->get('posters*'), $errors->get('poster_assignments*')) as $messages)
            @foreach ($messages as $message)
                <div class="text-danger small mt-1">{{ $message }}</div>
            @endforeach
        @endforeach
        @if ($posterDays->isEmpty())
            <p class="form-hint mt-2 mb-0">Poster dapat diunggah sekarang. Atur pilihan hari setelah Event Day dibuat.</p>
        @elseif ($uploadAssignments->isNotEmpty())
            <p class="form-hint mt-2 mb-0">Pilih kembali berkas poster yang sama untuk memulihkan pilihan harinya.</p>
        @endif
        <p class="text-danger small mb-0" data-poster-error role="status"></p>
        <div data-poster-removed></div>
        <input type="hidden" name="poster_primary" data-poster-primary disabled>

        <div class="event-poster-grid event-poster-editor-grid mt-3" data-poster-list>
            @foreach ($event->posters ?? [] as $poster)
                @include('events.partials.poster-tile', [
                    'key' => $poster->id,
                    'url' => route('events.posters.show', [$event, $poster]),
                    'thumbnail' => route('events.posters.show', [$event, $poster, 'size' => 'thumbnail']),
                    'name' => $poster->original_filename,
                    'primary' => $poster->is_primary,
                    'eventWide' => $poster->isEventWide(),
                    'assignedDays' => $poster->days->modelKeys(),
                ])
            @endforeach
        </div>

        <template data-poster-template>
            @include('events.partials.poster-tile', ['key' => '', 'url' => '', 'name' => '', 'primary' => false])
        </template>
    </div>
</section>
