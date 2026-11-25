@php($posterLimit = \App\Services\EventPosterService::uploadLimitLabel())
<section class="card mt-3" data-poster-editor data-limit="{{ config('event_assets.poster_max_count') }}"
    data-file-limit="{{ config('event_assets.poster_max_kb') * 1024 }}" data-file-limit-label="{{ $posterLimit }}"
    data-post-limit="{{ config('event_assets.post_max_bytes') }}">
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
        @foreach ($errors->get('posters*') as $messages)
            @foreach ($messages as $message)
                <div class="text-danger small mt-1">{{ $message }}</div>
            @endforeach
        @endforeach
        <p class="text-danger small mb-0" data-poster-error role="status"></p>
        <div data-poster-removed></div>
        <input type="hidden" name="poster_primary" data-poster-primary disabled>

        <div class="event-poster-grid mt-3" data-poster-list>
            @foreach ($event->posters ?? [] as $poster)
                @include('events.partials.poster-tile', [
                    'key' => $poster->id,
                    'url' => route('events.posters.show', [$event, $poster]),
                    'thumbnail' => route('events.posters.show', [$event, $poster, 'size' => 'thumbnail']),
                    'name' => $poster->original_filename,
                    'primary' => $poster->is_primary,
                ])
            @endforeach
        </div>

        <template data-poster-template>
            @include('events.partials.poster-tile', ['key' => '', 'url' => '', 'name' => '', 'primary' => false])
        </template>
    </div>
</section>
