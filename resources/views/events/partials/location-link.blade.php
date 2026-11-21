@if (\Illuminate\Support\Str::isUrl($mapsUrl, ['http', 'https']))
    <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer">{{ $location ?: 'Lokasi event' }}</a>
    @if ($showMapsHint ?? false)
        <div style="margin-top: 3px; color: #64748b; font-size: 0.85em; font-weight: normal;">Klik lokasi untuk membuka Google Maps</div>
    @endif
@else
    {{ $location ?: '–' }}
@endif
