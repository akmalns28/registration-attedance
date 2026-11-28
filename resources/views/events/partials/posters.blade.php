@if ($event->posters->isNotEmpty())
    @php
        $visiblePosters = $posterDay === null ? $event->posters : $event->posters->filter(fn ($poster) => $poster->appliesToDays([$posterDay->id]));
        $primary = $visiblePosters->firstWhere('is_primary', true);
    @endphp
    <section id="event-posters" class="mb-4">
        <h3>Poster Event</h3>
        @if ($event->days->isNotEmpty())
            <form method="GET" action="{{ route('events.show', $event) }}#event-posters" class="mb-3">
                <label for="poster-day-filter" class="form-label">Hari penggunaan poster</label>
                <div class="event-poster-filter d-flex gap-2 align-items-start">
                    <select name="poster_day" id="poster-day-filter" class="form-select w-auto">
                        <option value="">Semua Hari</option>
                        @foreach ($event->days as $day)
                            <option value="{{ $day->id }}" @selected($posterDay?->id === $day->id)>Day {{ $day->day_number }}{{ $day->title ? ' · '.$day->title : '' }} · {{ $day->date->format('d/m/Y') }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-outline-primary">Tampilkan</button>
                </div>
            </form>
        @endif

        @if ($primary)
            <figure class="mb-2">
                <a href="{{ route('events.posters.show', [$event, $primary]) }}" target="_blank" rel="noopener" class="d-inline-block">
                    <img src="{{ route('events.posters.show', [$event, $primary, 'size' => 'detail']) }}" alt="Poster utama {{ $event->title }}"
                        class="event-poster-hero rounded bg-light">
                </a>
                <figcaption class="small text-secondary mt-1">
                    @include('events.partials.poster-day-label', ['poster' => $primary])
                </figcaption>
            </figure>
        @endif

        @if ($visiblePosters->isEmpty())
            <p class="text-secondary mb-0">Belum ada poster untuk hari ini.</p>
        @else
            <div class="event-poster-grid mt-2">
                @foreach ($visiblePosters->where('id', '!=', $primary?->id) as $poster)
                    <figure class="mb-0">
                        <a href="{{ route('events.posters.show', [$event, $poster]) }}" target="_blank" rel="noopener">
                            <img src="{{ route('events.posters.show', [$event, $poster, 'size' => 'thumbnail']) }}" alt="{{ $poster->original_filename }}"
                                class="event-poster-thumb w-100 rounded" loading="lazy">
                        </a>
                        <figcaption class="small text-secondary mt-1 text-break">
                            @include('events.partials.poster-day-label', ['poster' => $poster])
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </section>
@endif
