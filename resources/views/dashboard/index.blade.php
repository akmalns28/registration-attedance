@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    <div class="container-xl">

        {{-- Page Header --}}
        <div class="page-header d-print-none">

            <div class="row align-items-center">

                <div class="col">

                    <div class="page-pretitle">
                        {{ config('app.name') }}
                    </div>

                    <h2 class="page-title">
                        Dashboard
                    </h2>

                    <div class="text-secondary mt-2">
                        Selamat datang, {{ $user->name }}.
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <span class="badge bg-primary-lt">
                        {{ now()->translatedFormat('d F Y') }}
                    </span>

                </div>

            </div>

        </div>

        {{-- Statistics --}}
        <div class="row row-deck row-cards mt-3">

            @php
                $statCards = [
                    [
                        'title' => 'Total Events',
                        'value' => $stats['total_events'],
                        'icon' => 'calendar-event',
                    ],
                    [
                        'title' => 'Total Participants',
                        'value' => $stats['total_participants'],
                        'icon' => 'users',
                    ],
                    [
                        'title' => 'Registered',
                        'value' => $stats['total_registered'],
                        'icon' => 'ticket',
                    ],
                    [
                        'title' => 'Attendance',
                        'value' => $stats['total_attendance'],
                        'icon' => 'clipboard-check',
                    ],
                ];
            @endphp

            @foreach ($statCards as $card)
                <div class="col-6 col-lg-3">

                    <div class="card h-100">

                        <div class="card-body">

                            <div class="d-flex align-items-center justify-content-between">

                                <div class="subheader">
                                    {{ $card['title'] }}
                                </div>

                                <x-ui.icon :name="$card['icon']" class="fs-2 text-secondary" />

                            </div>

                            <div class="h1 mb-1 mt-3">
                                {{ $card['value'] ?? '—' }}
                            </div>

                            @if ($card['value'] === null)
                                <div class="text-secondary small">Belum tersedia</div>
                            @endif
                        </div>

                    </div>

                </div>
            @endforeach

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
                            <div class="empty py-4">

                                <div class="empty-icon mb-3">

                                    <x-ui.icon name="calendar-off" class="fs-1 text-secondary" />

                                </div>

                                <p class="empty-title">
                                    Belum Ada Data Event
                                </p>

                                <p class="empty-subtitle text-secondary">
                                    Event terbaru akan tampil di sini setelah dibuat.
                                </p>

                            </div>
                        @endif

                    </div>

                </div>

            </div>

        </div>

    </div>

@endsection
