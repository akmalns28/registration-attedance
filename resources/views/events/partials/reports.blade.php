<div data-attendance-reports data-url="{{ route('events.reports.data', $event, absolute: false) }}">
    <h3 class="mb-1">Reports</h3>
    <p class="text-secondary">Registrasi aktif · Waktu check-in: {{ config('app.timezone') }}.</p>

    <form id="report-filters" data-report-filters data-filter-scope class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <label for="report-day" class="form-label">Event Day</label>
            <select id="report-day" name="report_day" class="form-select">
                <option value="">Semua Event Day</option>
                @foreach ($event->days as $reportDay)
                    <option value="{{ $reportDay->id }}">Day {{ $reportDay->day_number }} — {{ $reportDay->date->format('d/m/Y') }}{{ $reportDay->title ? ' — '.$reportDay->title : '' }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-lg-3">
            <label for="report-status" class="form-label">Status Kehadiran</label>
            <select id="report-status" name="report_status" data-filter-label="Status Kehadiran" class="form-select">
                <option value="">Semua Status</option><option value="present">Hadir</option><option value="not_present">Belum Hadir</option>
            </select>
        </div>
        <x-ui.compact-filters id="report-advanced-filters" class="col-12 col-lg-6">
            <x-slot:leading>
                <div class="flex-fill">
                    <label for="report-search" class="form-label">Cari peserta</label>
                    <input id="report-search" name="report_search" type="search" maxlength="200" class="form-control" placeholder="Nama atau nomor registrasi">
                </div>
            </x-slot:leading>
            <x-ui.compact-filter-select id="report-type" name="report_type" label="Jenis Peserta"
                :options="['' => 'Semua Jenis', 'employee' => 'Employee', 'external' => 'External']" />
            @include('organizations._filters', ['filterPrefix' => 'report', 'organizationSelection' => [
                'entity_id' => request()->query('report_entity'), 'department_id' => request()->query('report_department'),
                'division_id' => request()->query('report_division'),
            ]])
        </x-ui.compact-filters>
    </form>
    <p class="text-secondary small">Filter organisasi berlaku untuk Employee. Ringkasan mengikuti seluruh hasil filter dan pencarian.</p>

    <div data-report-feedback class="d-none" role="status" aria-live="polite" aria-atomic="true"></div>
    <button type="submit" form="report-filters" data-report-refresh class="btn btn-outline-primary btn-sm mb-3 d-none">
        <x-ui.icon name="refresh" class="me-1" /> Coba lagi
    </button>
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
