<div data-attendance-reports data-url="{{ route('events.reports.data', $event, absolute: false) }}">
    <h3 class="mb-1">Reports</h3>
    <p class="text-secondary">Registrasi aktif · Waktu check-in: {{ config('app.timezone') }}.</p>

    <form data-report-filters class="row g-3 align-items-end mb-3">
        <div class="col-12 col-md-4">
            <label for="report-day" class="form-label">Event Day</label>
            <select id="report-day" name="report_day" class="form-select">
                <option value="">Semua Event Day</option>
                @foreach ($event->days as $reportDay)
                    <option value="{{ $reportDay->id }}">Day {{ $reportDay->day_number }} — {{ $reportDay->date->format('d/m/Y') }}{{ $reportDay->title ? ' — '.$reportDay->title : '' }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label for="report-type" class="form-label">Jenis Peserta</label>
            <select id="report-type" name="report_type" class="form-select">
                <option value="">Semua Jenis</option><option value="employee">Employee</option><option value="external">External</option>
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label for="report-status" class="form-label">Status Kehadiran</label>
            <select id="report-status" name="report_status" class="form-select">
                <option value="">Semua Status</option><option value="present">Hadir</option><option value="not_present">Belum Hadir</option>
            </select>
        </div>
        @include('organizations._filters', ['filterPrefix' => 'report', 'organizationSelection' => [
            'entity_id' => request()->query('report_entity'), 'department_id' => request()->query('report_department'),
            'division_id' => request()->query('report_division'),
        ]])
        <div class="col-12 col-md-4">
            <label for="report-search" class="form-label">Pencarian</label>
            <input id="report-search" name="report_search" type="search" maxlength="200" class="form-control" placeholder="Nama peserta atau nomor registrasi">
        </div>
        <div class="col-12 col-md-4">
            <div class="d-flex gap-2">
                <button type="submit" data-report-refresh class="btn btn-primary flex-fill"><x-ui.icon name="refresh" class="me-1" /><span data-button-label>Perbarui</span></button>
                <button type="button" data-report-reset class="btn btn-outline-secondary flex-fill">Reset</button>
            </div>
        </div>
    </form>
    <p class="text-secondary small">Filter organisasi berlaku untuk Employee. Ringkasan mengikuti seluruh hasil filter dan pencarian.</p>

    <div data-report-feedback class="d-none" role="status" aria-live="polite" aria-atomic="true"></div>
    <div class="row row-cards mb-3" role="group" aria-label="Ringkasan Reports">
        @foreach (['expected' => 'Potensi Kehadiran', 'present' => 'Hadir', 'not_present' => 'Belum Hadir', 'percentage' => 'Persentase Kehadiran'] as $key => $title)
            <div class="col-6 col-xl-3"><x-ui.stat-card data-report-summary="{{ $key }}" :title="$title" value="—" /></div>
        @endforeach
    </div>

    @can('reports.export')
        <div class="d-flex flex-wrap gap-2 mb-2" role="group" aria-label="Ekspor laporan kehadiran">
            @foreach (['excel' => ['Export Excel', 'file-spreadsheet'], 'csv' => ['Export CSV', 'file-type-csv'], 'pdf' => ['Export PDF', 'file-type-pdf'], 'print' => ['Print', 'printer']] as $format => [$label, $icon])
                <button type="button" class="btn btn-outline-primary" data-report-export="{{ $format }}"
                    data-url="{{ route($format === 'print' ? 'events.reports.print' : 'events.reports.export.'.$format, $event, absolute: false) }}" disabled>
                    <x-ui.icon :name="$icon" class="me-1" /><span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
        <p class="text-secondary small">Ekspor mencakup seluruh hasil filter dan urutan tabel. PDF maksimal {{ number_format(config('attendance_reports.pdf_max_rows'), 0, ',', '.') }} baris; gunakan Excel, CSV, atau Print untuk laporan lebih besar.</p>
        <div data-report-export-feedback class="d-none" role="status" aria-live="polite"></div>
    @endcan

    <div class="table-responsive">
        <table id="event-attendance-report" data-attendance-report-table data-export="false" class="table table-vcenter table-hover w-100">
            <thead>
                <tr>
                    <th class="no-sort">No.</th><th>Nomor Registrasi</th><th>Nama Peserta</th><th>Jenis Peserta</th><th>Departemen</th>
                    <th>Event Day</th><th>Tanggal</th><th>Kehadiran</th><th>Waktu Check-in</th><th>Petugas</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <noscript><div class="alert alert-warning mt-3">Aktifkan JavaScript untuk memuat dan memfilter laporan kehadiran.</div></noscript>
</div>
