<div class="border rounded p-2 position-relative" data-poster-key="{{ $key }}">
    <input type="hidden" name="poster_order[]" value="{{ $key }}" data-poster-order disabled>
    <a href="{{ $url }}" target="_blank" rel="noopener" data-poster-preview>
        <img @if ($url) src="{{ $url }}" @endif alt="{{ $name }}" loading="lazy" class="event-poster-thumb w-100">
    </a>
    <div class="small mt-1 text-truncate" data-poster-name title="{{ $name }}">{{ $name }}</div>
    <div class="d-none d-flex flex-wrap gap-1 mt-1" data-poster-controls>
        <button type="button" class="btn btn-sm btn-outline-primary" data-poster-action="primary" aria-pressed="{{ $primary ? 'true' : 'false' }}"
            aria-label="Jadikan poster utama" title="Jadikan poster utama">★</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-poster-action="up" aria-label="Pindahkan poster lebih awal" title="Pindahkan lebih awal">↑</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-poster-action="down" aria-label="Pindahkan poster lebih akhir" title="Pindahkan lebih akhir">↓</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-poster-action="remove" aria-label="Hapus poster" title="Hapus poster">×</button>
    </div>
    <span class="badge bg-primary-lt position-absolute top-0 start-0 m-2 {{ $primary ? '' : 'd-none' }}" data-poster-badge>Utama</span>
</div>
