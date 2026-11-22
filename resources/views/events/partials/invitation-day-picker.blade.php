@php
    $availableDays = $event->days->filter(fn ($day) => $day->is_active && $day->date->betweenIncluded($event->start_date, $event->end_date));
@endphp

@if ($availableDays->isEmpty())
    <p class="form-hint mb-3">Belum ada hari aktif. Tetapkan hari undangan setelah hari pelaksanaan ditambahkan.</p>
@else
    <div class="mb-2">
        <label for="{{ $pickerId }}-mode" class="form-label required">Hari undangan</label>
        <select id="{{ $pickerId }}-mode" name="day_selection" class="form-select" required data-invitation-coverage>
            <option value="all" @selected(old('day_selection', 'all') === 'all')>Semua Hari (All Days)</option>
            <option value="selected" @selected(old('day_selection') === 'selected')>Pilih Hari (Selected Days)</option>
        </select>
    </div>
    <fieldset data-invitation-days @if (old('day_selection') !== 'selected') hidden disabled @endif>
        <legend class="form-label">Hari yang dipilih</legend>
        <div class="invitation-day-list overflow-auto">
            @foreach ($availableDays as $availableDay)
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" name="day_ids[]" value="{{ $availableDay->id }}"
                        @checked(in_array($availableDay->id, (array) old('day_ids', [])))>
                    <span class="form-check-label">Day {{ $availableDay->day_number }} · {{ $availableDay->date->format('d/m/Y') }}{{ $availableDay->title ? ' · '.$availableDay->title : '' }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endif
