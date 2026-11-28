@php
    $oldAssignment = $key === '' ? null : old('poster_assignments.'.$key);
    $hasOldAssignment = in_array($oldAssignment['scope'] ?? null, ['all', 'days'], true);
    $allDays = $hasOldAssignment ? $oldAssignment['scope'] === 'all' : ($eventWide ?? true);
    $selectedDays = $hasOldAssignment ? (array) ($oldAssignment['days'] ?? []) : ($assignedDays ?? []);
    $selectedCount = $posterDays->filter(fn ($day) => in_array($day->id, $selectedDays, true) || in_array((string) $day->id, $selectedDays, true))->count();
@endphp
<div class="border rounded p-2 position-relative" data-poster-key="{{ $key }}">
    <input type="hidden" name="poster_order[]" value="{{ $key }}" data-poster-order disabled>
    <a href="{{ $url }}" target="_blank" rel="noopener" data-poster-preview>
        <img @if ($url) src="{{ $thumbnail ?? $url }}" @endif alt="{{ $name }}" loading="lazy" class="event-poster-thumb w-100">
    </a>
    <div class="small mt-1 text-truncate" data-poster-name title="{{ $name }}">{{ $name }}</div>
    <span class="badge bg-secondary-lt mt-1" data-poster-day-count>{{ $allDays ? 'Semua Hari' : $selectedCount.' hari' }}</span>
    <div class="d-none d-flex flex-wrap gap-1 mt-1" data-poster-controls>
        <button type="button" class="btn btn-sm btn-outline-primary" data-poster-action="primary">{{ $primary ? 'Lepas status utama' : 'Jadikan utama' }}</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-poster-action="up" aria-label="Pindahkan poster lebih awal" title="Pindahkan lebih awal">↑</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-poster-action="down" aria-label="Pindahkan poster lebih akhir" title="Pindahkan lebih akhir">↓</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-poster-action="remove" aria-label="Hapus poster" title="Hapus poster">×</button>
    </div>
    @if ($posterDays->isNotEmpty())
        <details class="small mt-2" data-poster-assignment @if ($hasOldAssignment) data-poster-assignment-dirty open @endif hidden>
            <summary class="py-1">Atur hari</summary>
            <fieldset class="mt-1">
                <legend class="visually-hidden">Hari penggunaan poster</legend>
                <label class="form-check mb-0 py-1">
                    <input class="form-check-input" type="checkbox" data-poster-all-days @checked($allDays)>
                    <span class="form-check-label">Semua Hari</span>
                </label>
                <div class="event-poster-day-list overflow-auto">
                    @foreach ($posterDays as $day)
                        <label class="form-check mb-0 py-1">
                            <input class="form-check-input" type="checkbox" data-poster-day value="{{ $day->id }}"
                                @checked(! $allDays && (in_array($day->id, $selectedDays, true) || in_array((string) $day->id, $selectedDays, true)))>
                            <span class="form-check-label text-break">Day {{ $day->day_number }}{{ $day->title ? ' · '.$day->title : '' }}</span>
                            <span class="d-block text-secondary">{{ $day->date->format('d/m/Y') }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div data-poster-assignment-fields></div>
        </details>
    @endif
    <span class="badge bg-primary-lt position-absolute top-0 start-0 m-2 {{ $primary ? '' : 'd-none' }}" data-poster-badge>Utama</span>
</div>
