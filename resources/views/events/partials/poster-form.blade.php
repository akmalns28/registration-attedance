<section class="card mt-3" data-poster-editor data-limit="{{ config('event_assets.poster_max_count') }}">
    <div class="card-header"><h3 class="card-title">Poster Event</h3></div>
    <div class="card-body">
        <label for="event-posters" class="form-label">Tambah poster</label>
        <input id="event-posters" class="form-control" type="file" name="posters[]" multiple
            accept="image/jpeg,image/png,image/webp" aria-describedby="event-posters-help">
        <div class="form-hint" id="event-posters-help">
            JPG, PNG, atau WebP. Maksimal {{ config('event_assets.poster_max_count') }} poster,
            {{ config('event_assets.poster_max_kb') }} KB per poster dan 4000 × 4000 piksel.
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
