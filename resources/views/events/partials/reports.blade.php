<div data-attendance-reports data-url="{{ route('events.reports.data', $event) }}">
    <h3 class="mb-1">Reports</h3>
    <p class="text-secondary">Laporan kehadiran registrasi aktif untuk {{ $event->title }}. Waktu check-in menggunakan {{ config('app.timezone') }}.</p>

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
        <div class="col-12 col-md-4">
            <label for="report-department" class="form-label">Departemen</label>
            <select id="report-department" name="report_department" class="form-select" aria-describedby="report-department-help">
                <option value="">Semua Departemen</option>
                @foreach ($reportDepartments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label for="report-search" class="form-label">Pencarian</label>
            <input id="report-search" name="report_search" type="search" maxlength="200" class="form-control" placeholder="Nama peserta atau nomor registrasi">
        </div>
        <div class="col-12 col-md-4">
            <div class="d-flex gap-2">
                <button type="submit" data-report-refresh class="btn btn-primary flex-fill"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Perbarui</button>
                <button type="button" data-report-reset class="btn btn-outline-secondary flex-fill">Reset</button>
            </div>
        </div>
    </form>
    <p id="report-department-help" class="text-secondary small">Memilih departemen membatasi hasil ke peserta Employee. Ringkasan mengikuti seluruh filter dan pencarian, mencakup semua halaman tabel.</p>

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
                    data-url="{{ route($format === 'print' ? 'events.reports.print' : 'events.reports.export.'.$format, $event) }}" disabled>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icon-tabler-{{ $icon }} me-1" aria-hidden="true" focusable="false">
                        @switch($format)
                            @case('excel')
                                <path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" />
                                <path d="M8 11h8v7h-8l0 -7M8 15h8M11 11v7" />
                                @break
                            @case('csv')
                                <path d="M14 3v4a1 1 0 0 0 1 1h4M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4" />
                                <path d="M7 16.5a1.5 1.5 0 0 0 -3 0v3a1.5 1.5 0 0 0 3 0M10 20.25c0 .414 .336 .75 .75 .75h1.25a1 1 0 0 0 1 -1v-1a1 1 0 0 0 -1 -1h-1a1 1 0 0 1 -1 -1v-1a1 1 0 0 1 1 -1h1.25a.75 .75 0 0 1 .75 .75M16 15l2 6l2 -6" />
                                @break
                            @case('pdf')
                                <path d="M14 3v4a1 1 0 0 0 1 1h4M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4" />
                                <path d="M5 18h1.5a1.5 1.5 0 0 0 0 -3h-1.5v6M17 18h2M20 15h-3v6M11 15v6h1a2 2 0 0 0 2 -2v-2a2 2 0 0 0 -2 -2h-1" />
                                @break
                            @case('print')
                                <path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4M7 15a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2l0 -4" />
                                @break
                        @endswitch
                    </svg><span>{{ $label }}</span>
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
