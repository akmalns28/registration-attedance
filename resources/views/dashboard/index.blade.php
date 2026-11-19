@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none mb-3">
            <div class="row align-items-center">
                <div class="col">
                    <div class="page-pretitle">{{ config('app.name') }}</div>
                    <h2 class="page-title">Dashboard</h2>
                    <div class="text-secondary mt-2">Selamat datang, {{ $user->name }}.</div>
                </div>
                <div class="col-auto ms-auto">
                    <span class="badge bg-primary-lt">{{ now('Asia/Jakarta')->translatedFormat('d F Y') }}</span>
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('dashboard', absolute: false) }}" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="dashboard-event">Event</label>
                <select id="dashboard-event" name="event" class="form-select">
                    <option value="">All Events</option>
                    @foreach ($events as $event)
                        <option value="{{ $event->uuid }}" @selected($event->is($selectedEvent))>{{ $event->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-primary" type="submit">Terapkan</button></div>
        </form>

        <div class="row row-deck row-cards mb-3">
            @foreach ([
                'total_events' => 'Total Events',
                'active_events' => 'Active Events',
                'total_registered' => 'Active Registrations',
                'total_participants' => 'Total Participants (Unique)',
                'total_attendance' => 'Attendance Records',
                'attendance_percentage' => 'Attendance Percentage',
                'employee_registrations' => 'Internal Registrations',
                'external_registrations' => 'External Registrations',
            ] as $key => $title)
                <div class="col-6 col-lg-3">
                    <x-ui.stat-card :title="$title" :value="$stats[$key].($key === 'attendance_percentage' ? '%' : '')"
                        data-dashboard-stat="{{ $key }}" />
                </div>
            @endforeach
        </div>

        <div class="row row-cards mb-3">
            @foreach ([
                'registrations' => ['Registrasi per Event', 'Belum ada event.', 'col-lg-8'],
                'participants' => ['Distribusi Peserta', 'Belum ada registrasi aktif.', 'col-lg-4'],
                'attendance' => ['Kehadiran per Event Day', 'Belum ada hari pelaksanaan.', ''],
            ] as $key => [$title, $emptyMessage, $columns])
                <div class="col-12 {{ $columns }}">
                    <section class="card h-100">
                        <div class="card-header"><h3 class="card-title">{{ $title }}</h3></div>
                        <div class="card-body">
                            @if ($key === 'participants' ? array_sum($charts[$key]) === 0 : empty($charts[$key]))
                                <p class="text-secondary mb-0">{{ $emptyMessage }}</p>
                            @else
                                <div class="overflow-auto" style="max-height: 26rem">
                                    <div data-dashboard-chart="{{ $key }}" aria-label="{{ $title }}"></div>
                                </div>
                            @endif
                        </div>
                    </section>
                </div>
            @endforeach
            <script type="application/json" data-dashboard-chart-data>@json($charts)</script>
            <div class="col-12 d-none" data-dashboard-chart-error role="alert">
                <div class="alert alert-warning mb-0">Grafik gagal dimuat. Muat ulang halaman untuk mencoba kembali.</div>
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="row row-cards mt-3">

            <div class="col-lg-5">

                <div class="card h-100">

                    <div class="card-header">

                        <h3 class="card-title">
                            Quick Actions
                        </h3>

                    </div>

                    <div class="card-body">

                        <div class="row g-3">

                            @can('events.create')

                                @if (Route::has('events.create'))
                                    <div class="col-sm-6">

                                        <a href="{{ route('events.create') }}" class="btn btn-outline-primary w-100">
                                            <x-ui.icon name="calendar-plus" class="me-2" />
                                            Create Event
                                        </a>

                                    </div>
                                @endif

                            @endcan

                            @can('registrations.manage')

                                @if (Route::has('registrations.index'))
                                    <div class="col-sm-6">

                                        <a href="{{ route('registrations.index') }}" class="btn btn-outline-primary w-100">
                                            <x-ui.icon name="ticket" class="me-2" />
                                            Registrations
                                        </a>

                                    </div>
                                @endif

                            @endcan

                            @can('attendance.scan')

                                @if (Route::has('attendance.scanner'))
                                    <div class="col-sm-6">

                                        <a href="{{ route('attendance.scanner') }}" class="btn btn-outline-success w-100">
                                            <x-ui.icon name="qrcode" class="me-2" />
                                            QR Scanner
                                        </a>

                                    </div>
                                @endif

                            @endcan

                            @can('attendance.view')

                                @if (Route::has('attendance.index'))
                                    <div class="col-sm-6">

                                        <a href="{{ route('attendance.index') }}" class="btn btn-outline-primary w-100">
                                            <x-ui.icon name="clipboard-check" class="me-2" />
                                            Attendance
                                        </a>

                                    </div>
                                @endif

                            @endcan

                        </div>

                        <div class="text-secondary small mt-3">
                            Aksi yang tersedia mengikuti permission akun Anda.
                        </div>

                    </div>

                </div>

            </div>

            {{-- Recent Events --}}
            <div class="col-lg-7">

                <div class="card h-100">

                    <div class="card-header">

                        <h3 class="card-title">
                            Recent Events
                        </h3>

                    </div>

                    <div class="card-body">

                        @if ($recentEvents->isEmpty())
                            <p class="text-secondary mb-0">Belum ada event.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-vcenter" aria-label="Recent Events">
                                    <thead><tr><th>Event</th><th>Tanggal</th><th>Status</th><th>Registrasi Aktif</th><th>Kehadiran</th></tr></thead>
                                    <tbody>
                                        @foreach ($recentEvents as $event)
                                            @php($summary = $attendanceByEvent->get($event->uuid))
                                            <tr>
                                                <td>
                                                    @can('events.view')
                                                        <a href="{{ route('events.show', $event) }}">{{ $event->title }}</a>
                                                    @else
                                                        {{ $event->title }}
                                                    @endcan
                                                </td>
                                                <td class="text-nowrap">
                                                    {{ $event->start_date->format('d/m/Y') }}
                                                    @unless ($event->start_date->isSameDay($event->end_date))
                                                        &ndash; {{ $event->end_date->format('d/m/Y') }}
                                                    @endunless
                                                </td>
                                                <td><span class="badge bg-{{ ['published' => 'success', 'closed' => 'azure', 'cancelled' => 'danger'][$event->status] ?? 'secondary' }}-lt">{{ ucfirst($event->status) }}</span></td>
                                                <td>{{ $summary['active_registrations'] ?? 0 }}</td>
                                                <td>{{ $summary['percentage'] ?? 0 }}%</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                    </div>

                </div>

            </div>

        </div>

    </div>

@endsection
