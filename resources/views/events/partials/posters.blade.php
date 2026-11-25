@if ($event->posters->isNotEmpty())
    <section class="mb-4">
        <h3>Poster Event</h3>
        @php($primary = $event->posters->firstWhere('is_primary', true) ?? $event->posters->first())
        <a href="{{ route('events.posters.show', [$event, $primary]) }}" target="_blank" rel="noopener" class="d-inline-block">
            <img src="{{ route('events.posters.show', [$event, $primary]) }}" alt="Poster utama {{ $event->title }}"
                class="event-poster-hero rounded bg-light">
        </a>
        <div class="event-poster-grid mt-2">
            @foreach ($event->posters->where('id', '!=', $primary->id) as $poster)
                <a href="{{ route('events.posters.show', [$event, $poster]) }}" target="_blank" rel="noopener">
                    <img src="{{ route('events.posters.show', [$event, $poster]) }}" alt="{{ $poster->original_filename }}"
                        class="event-poster-thumb w-100 rounded" loading="lazy">
                </a>
            @endforeach
        </div>
    </section>
@endif
