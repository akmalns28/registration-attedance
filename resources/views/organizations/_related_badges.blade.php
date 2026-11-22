@php
    $export = $items->map(fn ($item) => isset($item['department'])
        ? $item['label'].' ('.$item['department'].')'
        : $item['label'])->implode('; ');
@endphp
<td data-search="{{ $export }}" data-order="{{ $total }}" data-export-value="{{ $export ?: '—' }}">
    @if ($items->isEmpty())
        <span class="text-secondary">—</span>
    @else
        <div class="d-flex flex-wrap align-items-center gap-1" style="max-width: 18rem">
            @foreach ($items->take(3) as $item)
                <span @class(['badge d-inline-block text-truncate', 'bg-primary-lt' => $item['active'], 'bg-secondary-lt' => !$item['active']])
                    style="max-width: 10rem" title="{{ $item['label'] }}{{ $item['active'] ? '' : ' (Tidak Aktif)' }}">{{ $item['label'] }}</span>
            @endforeach
            @if ($total > 3)
                <button type="button" class="btn btn-link btn-sm py-0 px-1 text-nowrap"
                    aria-controls="organization-related-modal" aria-haspopup="dialog"
                    data-organization-record="{{ $recordKey }}" data-organization-list="{{ $type }}"
                    aria-label="Lihat semua {{ $total }} {{ $label }} untuk {{ $entity->name }}">
                    +{{ $total - 3 }} lainnya
                </button>
            @endif
        </div>
    @endif
</td>
