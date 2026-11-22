@props(['id', 'table' => null, 'fallback' => false])

<div data-compact-filters @if ($table) data-filter-table="{{ $table }}" @endif {{ $attributes }}>
    <div class="d-flex align-items-end gap-2 compact-filter-toolbar">
        {{ $leading ?? '' }}
        <button type="button" class="btn btn-outline-secondary flex-shrink-0" data-filter-toggle
            aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $id }}" hidden>
            <x-ui.icon name="filter" class="me-1" /> Filter
        </button>
    </div>
    <div id="{{ $id }}" class="compact-filter-panel bg-body border rounded shadow-sm p-3"
        data-filter-panel role="dialog" aria-label="Filter" @unless($fallback) hidden @endunless>
        <div class="d-none justify-content-between align-items-center gap-2 mb-3" data-filter-panel-header>
            <strong>Filter</strong>
            <button type="button" class="btn-close" data-filter-close aria-label="Tutup filter"></button>
        </div>
        <div class="row g-2">{{ $slot }}</div>
        <div class="d-none border-top mt-3 pt-2" data-filter-panel-footer>
            <button type="button" class="btn btn-sm btn-ghost-secondary" data-filter-reset>Reset Filter</button>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2" data-filter-chips role="group" aria-label="Filter aktif" hidden></div>
</div>
