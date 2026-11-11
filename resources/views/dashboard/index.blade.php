@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    <div class="container-xl">

        {{-- Page Header --}}
        <div class="page-header d-print-none">

            <div class="row align-items-center">

                <div class="col">

                    <div class="page-pretitle">
                        Registration Attendance
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

        {{-- Development Information --}}
        <div class="alert alert-info mt-3" role="alert">

            <div class="d-flex align-items-start gap-2">

                <i class="ti ti-info-circle fs-2"></i>

                <div>
                    <strong>Dashboard Preview</strong>

                    <div class="mt-1">
                        Statistik event, registrasi, dan absensi akan
                        ditampilkan setelah modul terkait selesai
                        diimplementasikan.
                    </div>
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
                        'icon' => 'ti ti-calendar-event',
                    ],
                    [
                        'title' => 'Total Participants',
                        'value' => $stats['total_participants'],
                        'icon' => 'ti ti-users',
                    ],
                    [
                        'title' => 'Registered',
                        'value' => $stats['total_registered'],
                        'icon' => 'ti ti-ticket',
                    ],
                    [
                        'title' => 'Attendance',
                        'value' => $stats['total_attendance'],
                        'icon' => 'ti ti-clipboard-check',
                    ],
                ];
            @endphp

            @foreach ($statCards as $card)
                <div class="col-sm-6 col-lg-3">

                    <div class="card h-100">

                        <div class="card-body">

                            <div class="d-flex align-items-center justify-content-between">

                                <div class="subheader">
                                    {{ $card['title'] }}
                                </div>

                                <i class="{{ $card['icon'] }} fs-2 text-secondary"></i>

                            </div>

                            <div class="h1 mb-1 mt-3">
                                {{ $card['value'] ?? '—' }}
                            </div>

                            <div class="text-secondary small">
                                {{ $card['value'] === null ? 'Belum tersedia' : 'Data tersedia' }}
                            </div>

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
                                            <i class="ti ti-calendar-plus me-2"></i>
                                            Create Event
                                        </a>

                                    </div>
                                @endif

                            @endcan

                            @can('registrations.manage')

                                @if (Route::has('registrations.index'))
                                    <div class="col-sm-6">

                                        <a href="{{ route('registrations.index') }}" class="btn btn-outline-primary w-100">
                                            <i class="ti ti-ticket me-2"></i>
                                            Registrations
                                        </a>

                                    </div>
                                @endif

                            @endcan

                            @can('attendance.scan')

                                @if (Route::has('attendance.scanner'))
                                    <div class="col-sm-6">

                                        <a href="{{ route('attendance.scanner') }}" class="btn btn-outline-success w-100">
                                            <i class="ti ti-qrcode me-2"></i>
                                            QR Scanner
                                        </a>

                                    </div>
                                @endif

                            @endcan

                            @can('attendance.view')

                                @if (Route::has('attendance.index'))
                                    <div class="col-sm-6">

                                        <a href="{{ route('attendance.index') }}" class="btn btn-outline-primary w-100">
                                            <i class="ti ti-clipboard-check me-2"></i>
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

                                    <i class="ti ti-calendar-off fs-1 text-secondary"></i>

                                </div>

                                <p class="empty-title">
                                    Belum Ada Data Event
                                </p>

                                <p class="empty-subtitle text-secondary">
                                    Daftar event terbaru akan tampil setelah
                                    modul Event Management tersedia.
                                </p>

                            </div>
                        @endif

                    </div>

                </div>

            </div>

        </div>

    </div>

@endsection
