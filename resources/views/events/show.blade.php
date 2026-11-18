@extends('layouts.app')

@section('title', $event->title)

@section('content')
    @php
        $statusClasses = [
            'draft' => 'bg-secondary-lt',
            'published' => 'bg-success-lt',
            'closed' => 'bg-azure-lt',
            'cancelled' => 'bg-danger-lt',
        ];

        $statusClass = $statusClasses[$event->status] ?? 'bg-secondary-lt';

        $duration = (int) $event->start_date->diffInDays($event->end_date) + 1;

        $totalSchedules = $event->days->sum('schedules_count');

        $participantErrors = collect($errors->getMessages())
            ->filter(fn (array $messages, string $key): bool => in_array($key, ['participants', 'participant_ids', 'participant_type', 'event', 'registration'], true) || str_starts_with($key, 'participant_ids.'))
            ->flatten();
    @endphp

    <div class="container-xl" data-event-details
        data-participant-errors="{{ $participantErrors->isNotEmpty() ? 'true' : 'false' }}"
        data-participant-type="{{ $participantErrors->isNotEmpty() ? old('participant_type', '') : '' }}">

        {{-- Header --}}
        <div class="page-header d-print-none">
            <div class="row align-items-center g-3">
                <div class="col">

                    <div class="page-pretitle">
                        Event Management
                    </div>

                    <h2 class="page-title">
                        {{ $event->title }}
                    </h2>

                    <div class="text-secondary mt-2 d-flex flex-wrap align-items-center gap-1">
                        <span>
                            <x-ui.icon name="calendar" class="me-1" />
                            {{ $event->start_date->format('d M Y') }}

                            @if ($event->isMultiDay())
                                - {{ $event->end_date->format('d M Y') }}
                            @endif
                        </span>

                        @if ($event->location)
                            <span class="mx-1" aria-hidden="true">•</span>
                            <span class="text-break">
                                <x-ui.icon name="map-pin" class="me-1" />
                                {{ $event->location }}
                            </span>
                        @endif
                        <span class="mx-1" aria-hidden="true">•</span>
                        <span class="badge {{ $statusClass }}">{{ ucfirst($event->status) }}</span>
                    </div>

                </div>

                <div class="col-auto ms-auto">
                    <div class="btn-list">

                        <a href="{{ route('events.index') }}" class="btn btn-outline-secondary">
                            <x-ui.icon name="arrow-left" class="me-1" />
                            Kembali
                        </a>

                        @can('attendance.scan')
                            @if ($event->isPublished())
                                <a href="{{ route('events.scanner', $event) }}" class="btn btn-success">
                                    <x-ui.icon name="qrcode" class="me-1" />
                                    Scan QR
                                </a>
                            @endif
                        @endcan

                        @can('events.update')
                            <a href="{{ route('events.edit', $event) }}" class="btn btn-primary">
                                <x-ui.icon name="pencil" class="me-1" />
                                Edit Event
                            </a>
                        @endcan

                    </div>
                </div>
            </div>
        </div>

        {{-- Statistics --}}
        <div class="row row-cards mt-3" role="group" aria-label="Ringkasan event">

            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Durasi Event</div>

                        <div class="h1 mb-0">
                            {{ $duration }}
                            <span class="fs-5 text-secondary">Hari</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Event Days</div>

                        <div class="h1 mb-0">
                            {{ $event->days->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Total Rundown</div>

                        <div class="h1 mb-0">
                            {{ $totalSchedules }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">PERSENTASE KEHADIRAN EVENT</div>

                        <div class="h1 mb-0">{{ $eventAttendanceSummary['percentage'] }}%</div>
                        <div class="text-secondary small mt-1">
                            {{ $eventAttendanceSummary['checked_in'] }} dari {{ $eventAttendanceSummary['expected_attendance'] }} potensi kehadiran
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- Event Navigation --}}
        <div class="card mt-3">

            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" role="tablist">

                    <li class="nav-item" role="presentation">
                        <a href="#event-overview" class="nav-link active" data-bs-toggle="tab" role="tab"
                            aria-controls="event-overview" aria-selected="true">
                            <x-ui.icon name="info-circle" class="me-2" />
                            Overview
                        </a>
                    </li>

                    <li class="nav-item" role="presentation">
                        <a href="#event-days" class="nav-link" data-bs-toggle="tab" role="tab"
                            aria-controls="event-days" aria-selected="false">
                            <x-ui.icon name="calendar-event" class="me-2" />
                            Event Days & Rundown

                            <span class="badge bg-primary-lt ms-2">
                                {{ $event->days->count() }}
                            </span>
                        </a>
                    </li>

                    <li class="nav-item" role="presentation">

                        <a href="#event-participants" class="nav-link" data-bs-toggle="tab" role="tab"
                            aria-controls="event-participants" aria-selected="false">

                            <x-ui.icon name="users" class="me-2" />

                            Participants

                            <span class="badge bg-primary-lt ms-2">
                                {{ $event->registered_participants_count }}
                            </span>

                        </a>

                    </li>

                    @can('reports.view')
                        <li class="nav-item" role="presentation">
                            <a href="#event-reports" id="event-reports-tab" class="nav-link" data-bs-toggle="tab" role="tab"
                                aria-controls="event-reports" aria-selected="false">
                                <x-ui.icon name="report-analytics" class="me-2" />Reports
                            </a>
                        </li>
                    @endcan
                </ul>
            </div>

            <div class="card-body">
                <div class="tab-content">

                    {{-- Overview Tab --}}
                    <div id="event-overview" class="tab-pane fade show active" role="tabpanel">

                        <h3 class="mb-3">
                            Informasi Event
                        </h3>

                        <div class="datagrid">

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Nama Event
                                </div>

                                <div class="datagrid-content">
                                    {{ $event->title }}
                                </div>
                            </div>

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Lokasi
                                </div>

                                <div class="datagrid-content">
                                    {{ $event->location ?: '-' }}
                                </div>
                            </div>

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Tanggal Mulai
                                </div>

                                <div class="datagrid-content">
                                    {{ $event->start_date->format('d/m/Y') }}
                                </div>
                            </div>

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Tanggal Selesai
                                </div>

                                <div class="datagrid-content">
                                    {{ $event->end_date->format('d/m/Y') }}
                                </div>
                            </div>

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Durasi
                                </div>

                                <div class="datagrid-content">
                                    {{ $duration }} Hari
                                </div>
                            </div>

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Kapasitas Peserta
                                </div>

                                <div class="datagrid-content">
                                    {{ $event->capacity ? number_format($event->capacity, 0, ',', '.') : 'Unlimited' }}
                                </div>
                            </div>

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Registrasi Dibuka
                                </div>

                                <div class="datagrid-content">
                                    {{ $event->registration_start_at ? $event->registration_start_at->format('d/m/Y H:i') : 'Belum ditentukan' }}
                                </div>
                            </div>

                            <div class="datagrid-item">
                                <div class="datagrid-title">
                                    Registrasi Ditutup
                                </div>

                                <div class="datagrid-content">
                                    {{ $event->registration_end_at ? $event->registration_end_at->format('d/m/Y H:i') : 'Belum ditentukan' }}
                                </div>
                            </div>

                        </div>

                        <hr class="my-4">

                        <h3>Deskripsi</h3>

                        <div class="text-secondary">
                            {!! nl2br(e($event->description ?: 'Tidak ada deskripsi.')) !!}
                        </div>

                    </div>

                    {{-- Event Days Tab --}}
                    <div id="event-days" class="tab-pane fade" role="tabpanel">

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

                            <div>
                                <h3 class="mb-1">
                                    Hari Pelaksanaan & Rundown
                                </h3>

                                <div class="text-secondary">
                                    Kelola tanggal pelaksanaan dan agenda
                                    kegiatan setiap hari.
                                </div>
                            </div>

                            @can('events.update')
                                <a href="{{ route('events.days.create', $event) }}" class="btn btn-primary">
                                    <x-ui.icon name="plus" class="me-2" />
                                    Tambah Event Day
                                </a>
                            @endcan

                        </div>

                        @forelse ($event->days as $day)
                            <div class="card mb-3">

                                <div class="card-header d-flex flex-wrap gap-3">

                                    <div class="flex-fill">

                                        <h3 class="card-title">
                                            Day {{ $day->day_number }}

                                            @if ($day->title)
                                                — {{ $day->title }}
                                            @endif
                                        </h3>

                                        <div class="text-secondary small mt-1">
                                            <x-ui.icon name="calendar" class="me-1" />
                                            {{ $day->date->format('d M Y') }}
                                        </div>

                                    </div>

                                    <div class="card-actions">

                                        <div class="btn-list align-items-center">

                                            <span class="badge {{ $day->is_active ? 'bg-success-lt' : 'bg-danger-lt' }}">
                                                {{ $day->is_active ? 'Active' : 'Inactive' }}
                                            </span>

                                            <x-ui.action icon="eye" label="Detail Event Day" :href="route('events.days.show', [$event, $day])" />

                                            @can('events.update')
                                                <x-ui.action icon="pencil" label="Edit Event Day" variant="primary" :href="route('events.days.edit', [$event, $day])" />

                                                <x-ui.action icon="trash" :label="'Hapus Day '.$day->day_number" variant="danger"
                                                    data-bs-toggle="modal" data-bs-target="#deleteEventDayModal"
                                                    data-delete-url="{{ route('events.days.destroy', [$event, $day]) }}"
                                                    data-delete-name="Day {{ $day->day_number }}" />
                                            @endcan

                                        </div>

                                    </div>

                                </div>

                                <div class="card-body py-3 d-flex flex-wrap gap-3">
                                    <span><x-ui.icon name="list-details" class="me-1" />{{ $day->schedules_count }} kegiatan rundown</span>
                                    <span class="text-secondary">
                                        <x-ui.icon name="clock" class="me-1" />
                                        {{ $day->schedules_min_start_time ? substr($day->schedules_min_start_time, 0, 5).' - '.substr($day->schedules_max_end_time, 0, 5) : 'Belum ada rundown' }}
                                    </span>
                                    <span><x-ui.icon name="users" class="me-1" />{{ $day->checked_in_count }} / {{ $event->registered_participants_count }} peserta hadir</span>
                                </div>

                            </div>

                        @empty

                            <div class="empty">

                                <div class="empty-icon">
                                    <x-ui.icon name="calendar-off" class="fs-1 text-secondary" />
                                </div>

                                <p class="empty-title">
                                    Belum Ada Event Day
                                </p>

                                <p class="empty-subtitle text-secondary">
                                    Tambahkan hari pelaksanaan untuk mulai
                                    menyusun rundown event.
                                </p>

                            </div>
                        @endforelse

                    </div>

                    {{-- Participants Tab --}}

                    <div id="event-participants" class="tab-pane fade" role="tabpanel">

                        @include('events.partials.participants')

                    </div>

                    @can('reports.view')
                        <section id="event-reports" class="tab-pane fade" role="tabpanel" aria-labelledby="event-reports-tab">
                            @include('events.partials.reports')
                        </section>
                    @endcan

                </div>
            </div>
        </div>

    </div>

    {{-- Delete Confirmation Modals --}}
    @can('events.update')

        @foreach ([
                [
                    'id' => 'deleteEventDayModal',
                    'form' => 'deleteEventDayForm',
                    'name' => 'deleteEventDayName',
                    'title' => 'Hapus Event Day?',
                    'description' => 'Event Day hanya dapat dihapus jika belum memiliki rundown.',
                ],
            ] as $modal)
            <div class="modal modal-blur fade" id="{{ $modal['id'] }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-sm modal-dialog-centered">
                    <div class="modal-content">

                        <div class="modal-status bg-danger"></div>

                        <div class="modal-body text-center py-4">

                            <x-ui.icon name="alert-triangle" class="text-danger fs-1" />

                            <h3 class="mt-3">
                                {{ $modal['title'] }}
                            </h3>

                            <p class="text-secondary">
                                Yakin ingin menghapus
                                <strong id="{{ $modal['name'] }}"></strong>?
                            </p>

                            <p class="text-secondary small">
                                {{ $modal['description'] }}
                            </p>

                        </div>

                        <div class="modal-footer">

                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                Batal
                            </button>

                            <form id="{{ $modal['form'] }}" method="POST" data-form data-loading-text="Menghapus...">
                                @csrf
                                @method('DELETE')

                                <button type="submit" class="btn btn-danger">
                                    Ya, Hapus
                                </button>

                            </form>

                        </div>

                    </div>
                </div>
            </div>
        @endforeach

    @endcan


    @include('events.partials.add-employee-modal')

    @include('events.partials.add-external-modal')
    @include('events.partials.cancel-registration-modal')
    @include('events.partials.reactivate-registration-modal')
@endsection
