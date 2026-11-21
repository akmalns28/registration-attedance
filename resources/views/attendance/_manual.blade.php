<section class="card" data-manual-attendance
    @if ($fixedDay)
        data-search-url="{{ route('events.days.manual.search', [$fixedEvent, $fixedDay], absolute: false) }}"
        data-checkin-url="{{ route('events.days.manual.check-in', [$fixedEvent, $fixedDay], absolute: false) }}"
        data-event-id="{{ $fixedEvent->id }}" data-day-id="{{ $fixedDay->id }}"
    @else
        data-search-url="{{ route('attendance.manual.search', absolute: false) }}"
        data-checkin-url="{{ route('attendance.manual.check-in', absolute: false) }}"
    @endif>
    <div class="card-body">
        <fieldset data-manual-controls class="mb-3">
            @unless ($fixedDay)
            <div class="row g-3 mb-3">
                <div class="col-12 col-sm-6">
                    <label for="manual-event" class="form-label">Event</label>
                    <select id="manual-event" class="form-select" @disabled($fixedEvent !== null)>
                        @unless ($fixedEvent)<option value="">Pilih Event Published</option>@endunless
                        @foreach ($events as $scannerEvent)
                            <option value="{{ $scannerEvent['id'] }}" @selected($fixedEvent?->id === $scannerEvent['id'])>{{ $scannerEvent['title'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="manual-day" class="form-label">Hari Pelaksanaan</label>
                    <select id="manual-day" class="form-select" disabled>
                        <option value="">Pilih hari pelaksanaan</option>
                    </select>
                </div>
            </div>
            @endunless
            <fieldset class="mb-3">
                <legend class="form-label">Jenis Peserta</legend>
                <div class="btn-group" role="group" aria-label="Jenis Peserta">
                    <input type="radio" class="btn-check" name="manual_type" id="manual-employee" value="employee" checked>
                    <label class="btn btn-outline-primary" for="manual-employee">Internal</label>
                    <input type="radio" class="btn-check" name="manual_type" id="manual-external" value="external">
                    <label class="btn btn-outline-primary" for="manual-external">External</label>
                </div>
            </fieldset>
            <label for="manual-search" class="form-label">Cari Peserta</label>
            <input type="search" id="manual-search" class="form-control" maxlength="100" autocomplete="off"
                placeholder="Cari NIK/NIP atau nama karyawan" aria-describedby="manual-status" aria-controls="manual-results" disabled>
            <div class="d-flex align-items-center gap-2 mt-1">
                <span class="spinner-border spinner-border-sm d-none" data-manual-loading aria-hidden="true"></span>
                <div id="manual-status" class="form-hint mt-0" role="status" aria-live="polite">{{ $fixedDay ? 'Ketik minimal 2 karakter.' : 'Pilih event dan hari pelaksanaan.' }}</div>
            </div>
            <div class="list-group shadow-sm mt-2 overflow-auto" style="max-height: 18rem" id="manual-results" data-manual-results></div>
        </fieldset>

        <section class="border rounded p-3" data-manual-confirmation hidden aria-label="Konfirmasi peserta">
            <h3 class="h4" data-manual-name></h3>
            <p class="text-secondary mb-2" data-manual-details></p>
            <span data-manual-attendance-status></span>
            <div class="btn-list mt-3">
                <button type="button" class="btn btn-success" data-manual-confirm>Konfirmasi Check-in</button>
                <button type="button" class="btn btn-outline-secondary" data-manual-cancel>Batal</button>
            </div>
        </section>
    </div>
</section>
