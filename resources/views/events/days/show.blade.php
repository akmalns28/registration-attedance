@extends('layouts.app')

@section('title', 'Day '.$day->day_number.' - '.$event->title)

@section('content')
    <div data-event-details data-event-day-details data-event-id="{{ $event->id }}" data-day-id="{{ $day->id }}" data-attendance-refresh-url="{{ $attendanceRefreshUrl }}">
        <x-ui.page-header :title="'Day '.$day->day_number.($day->title ? ' - '.$day->title : '')"
            :subtitle="$event->title.' - '.$day->date->format('d M Y')">
            <x-slot:actions>
                <a href="{{ route('events.show', $event) }}#event-days" class="btn btn-outline-secondary">
                    <x-ui.icon name="arrow-left" class="me-1" />Kembali ke Event
                </a>
                @can('events.update')
                    <a href="{{ route('events.days.edit', [$event, $day]) }}" class="btn btn-primary">
                        <x-ui.icon name="pencil" class="me-1" />Edit Hari
                    </a>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-secondary-lt">Event: {{ ucfirst($event->status) }}</span>
            <span class="badge {{ $day->is_active ? 'bg-success-lt' : 'bg-danger-lt' }}">{{ $day->is_active ? 'Hari Aktif' : 'Hari Tidak Aktif' }}</span>
        </div>

        @if ($day->description)
            <p class="text-secondary">{{ $day->description }}</p>
        @endif

        <div class="row g-3 mb-3" aria-label="Ringkasan hari pelaksanaan">
            <div class="col-6 col-lg-3"><x-ui.stat-card data-attendance-summary="invited" title="Peserta Diundang" :value="$attendanceSummary['invited']" /></div>
            <div class="col-6 col-lg-3"><x-ui.stat-card data-attendance-summary="checked_in" title="Sudah Check-in" :value="$attendanceSummary['checked_in']" /></div>
            <div class="col-6 col-lg-3"><x-ui.stat-card data-attendance-summary="not_checked_in" title="Belum Check-in" :value="$attendanceSummary['not_checked_in']" /></div>
            <div class="col-6 col-lg-3"><x-ui.stat-card data-attendance-summary="percentage" title="Persentase Kehadiran" :value="$attendanceSummary['percentage'].'%'" /></div>
        </div>

        <p class="text-secondary small">Ringkasan menghitung undangan aktif untuk hari ini. Riwayat check-in tersimpan setelah undangan dicabut atau registrasi dibatalkan. Waktu check-in menggunakan {{ config('app.timezone') }}.</p>

        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a href="#day-rundown" id="day-rundown-tab" class="nav-link active" data-bs-toggle="tab" role="tab"
                            aria-controls="day-rundown" aria-selected="true">Rundown</a>
                    </li>
                    @if ($canViewInvitations)
                        <li class="nav-item" role="presentation">
                            <a href="#day-invitations" id="day-invitations-tab" class="nav-link" data-bs-toggle="tab" role="tab"
                                aria-controls="day-invitations" aria-selected="false">Peserta Diundang</a>
                        </li>
                    @endif
                    @can('attendance.scan')
                        <li class="nav-item" role="presentation">
                            <a href="#day-scanner" id="day-scanner-tab" class="nav-link" data-bs-toggle="tab" role="tab"
                                aria-controls="day-scanner" aria-selected="false">Scan QR</a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a href="#day-manual" id="day-manual-tab" class="nav-link" data-bs-toggle="tab" role="tab"
                                aria-controls="day-manual" aria-selected="false">Manual Check-in</a>
                        </li>
                    @endcan
                    @can('attendance.view')
                        <li class="nav-item" role="presentation">
                            <a href="#day-attendees" id="day-attendees-tab" class="nav-link" data-bs-toggle="tab" role="tab"
                                aria-controls="day-attendees" aria-selected="false">Peserta Hadir</a>
                        </li>
                    @endcan
                </ul>
            </div>
            <div class="card-body tab-content">
                <section id="day-rundown" class="tab-pane fade show active" role="tabpanel" aria-labelledby="day-rundown-tab">
                    @include('events.partials.schedules', ['enableScheduleDataTable' => $day->schedules->isNotEmpty()])
                </section>
                @if ($canViewInvitations)
                    <section id="day-invitations" class="tab-pane fade" role="tabpanel" aria-labelledby="day-invitations-tab">
                        @include('events.days.invitees')
                    </section>
                @endif
                @can('attendance.scan')
                    <section id="day-scanner" class="tab-pane fade" role="tabpanel" aria-labelledby="day-scanner-tab">
                        @if ($scannerData)
                            @include('attendance._scanner', $scannerData)
                        @else
                            <div class="alert alert-warning mb-0">Validasi QR hanya tersedia untuk Event Published dengan hari pelaksanaan aktif dalam rentang tanggal event.</div>
                        @endif
                    </section>
                    <section id="day-manual" class="tab-pane fade" role="tabpanel" aria-labelledby="day-manual-tab">
                        @if ($scannerData)
                            @include('attendance._manual', $scannerData)
                        @else
                            <div class="alert alert-warning mb-0">Manual Check-in hanya tersedia untuk Event Published dengan hari pelaksanaan aktif dalam rentang tanggal event.</div>
                        @endif
                    </section>
                @endcan
                @can('attendance.view')
                    <section id="day-attendees" class="tab-pane fade" role="tabpanel" aria-labelledby="day-attendees-tab">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <h3 class="mb-0">Peserta Hadir</h3>
                            <button type="button" data-attendance-refresh class="btn btn-outline-primary"><x-ui.icon name="refresh" class="me-1" />Perbarui Kehadiran</button>
                        </div>
                        <div data-attendance-refresh-feedback class="d-none" role="status" aria-live="polite"></div>
                        <div class="table-responsive">
                            <table id="day-attendance-{{ $day->id }}" data-datatable data-attendance-table class="table table-vcenter w-100">
                                <thead><tr><th>Nomor Registrasi</th><th>Nama Peserta</th><th>Jenis Peserta</th><th>Status Registrasi</th><th>Waktu Check-in</th><th>Petugas</th><th>Kehadiran</th></tr></thead>
                                <tbody>
                                    @foreach ($attendees as $attendee)
                                        <tr>
                                            <td>{{ $attendee['registration_number'] }}</td><td>{{ $attendee['participant_name'] }}</td>
                                            <td>{{ $attendee['participant_type'] }}</td><td>{{ $attendee['registration_status'] }}</td>
                                            <td>{{ $attendee['checkin_time'] }}</td><td>{{ $attendee['operator_name'] }}</td><td>Hadir</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endcan
            </div>
        </div>
    </div>

    @can('events.update')
        @if ($event->status !== 'cancelled')
            @include('events.days.invitations')
        @endif
        <x-ui.modal id="deleteScheduleModal" title="Hapus Rundown?" size="sm">
            <p>Yakin ingin menghapus <strong id="deleteScheduleName"></strong>?</p>
            <p class="text-secondary small">Rundown yang dihapus tidak dapat dipulihkan.</p>
            <x-slot:footer>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <form id="deleteScheduleForm" method="POST" data-form data-loading-text="Menghapus...">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Ya, Hapus</button>
                </form>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
@endsection
