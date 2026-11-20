@php
    $availableDays = $event->days->filter(fn ($day) => $day->is_active && $day->date->betweenIncluded($event->start_date, $event->end_date));
@endphp

@if ($availableDays->isEmpty())
    <p class="form-hint mb-3">Belum ada hari aktif. Tetapkan hari undangan setelah hari pelaksanaan ditambahkan.</p>
@else
    <div class="mb-3">
        <label for="{{ $pickerId }}-mode" class="form-label required">Hari undangan</label>
        <select id="{{ $pickerId }}-mode" name="day_selection" class="form-select" required>
            <option value="">Pilih cakupan undangan</option>
            <option value="all" @selected(old('day_selection') === 'all')>Semua Hari (All Days)</option>
            <option value="selected" @selected(old('day_selection') === 'selected')>Pilih Hari (Select Days)</option>
        </select>
    </div>
    <div class="mb-3">
        <label for="{{ $pickerId }}-days" class="form-label">Hari yang dipilih</label>
        <select id="{{ $pickerId }}-days" name="day_ids[]" class="form-select" multiple data-tom-select
            data-placeholder="Pilih satu atau beberapa hari" aria-describedby="{{ $pickerId }}-help">
            @foreach ($availableDays as $availableDay)
                <option value="{{ $availableDay->id }}" @selected(in_array($availableDay->id, (array) old('day_ids', [])))>
                    Day {{ $availableDay->day_number }} · {{ $availableDay->date->format('d/m/Y') }}{{ $availableDay->title ? ' · '.$availableDay->title : '' }}
                </option>
            @endforeach
        </select>
        <div id="{{ $pickerId }}-help" class="form-hint">Isi daftar ini untuk Pilih Hari. Semua Hari memakai hari aktif yang tersedia saat registrasi.</div>
    </div>
@endif
