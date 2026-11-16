{{-- Participant Summary --}}

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h3 class="mb-1">
            Event Participants
        </h3>

        <div class="text-secondary">
            Kelola peserta Employee dan External Participant
            yang mengikuti event ini.
        </div>
    </div>

    <div class="btn-list">
        <a href="{{ route('notifications.index', ['event' => $event->uuid]) }}" class="btn btn-outline-primary">
            <i class="ti ti-mail me-2"></i> Notifications
        </a>
        @can('events.update')
            @if (in_array($event->status, ['draft', 'published'], true))
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                    <i class="ti ti-user-plus me-2"></i>
                    Add Employee
                </button>

                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal"
                    data-bs-target="#addExternalModal">
                    <i class="ti ti-users-plus me-2"></i>
                    Add External
                </button>
            @endif
        @endcan
    </div>

</div>

{{-- Summary Cards --}}

<div class="row row-cards mb-4">

    <div class="col-sm-6 col-lg-3">
        <div class="card">

            <div class="card-body">

                <div class="subheader">
                    Total Registered
                </div>

                <div class="h1 mb-0">
                    {{ $event->registered_participants_count }}
                </div>

                <div class="text-secondary small mt-2">
                    Peserta aktif terdaftar
                </div>

            </div>

        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card">

            <div class="card-body">

                <div class="subheader">
                    Employees
                </div>

                <div class="h1 mb-0">
                    {{ $event->registered_employees_count }}
                </div>

                <div class="text-secondary small mt-2">
                    Peserta internal
                </div>

            </div>

        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card">

            <div class="card-body">

                <div class="subheader">
                    External Participants
                </div>

                <div class="h1 mb-0">
                    {{ $event->registered_externals_count }}
                </div>

                <div class="text-secondary small mt-2">
                    Peserta eksternal
                </div>

            </div>

        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card">

            <div class="card-body">

                <div class="subheader">
                    Remaining Capacity
                </div>

                <div class="h1 mb-0">
                    {{ $remainingCapacity === null ? 'Unlimited' : number_format($remainingCapacity, 0, ',', '.') }}
                </div>

                <div class="text-secondary small mt-2">
                    Sisa kapasitas peserta
                </div>

            </div>

        </div>
    </div>

</div>

{{-- Registration Information --}}

@if ($event->status === 'draft')
    <div class="alert alert-info">

        <i class="ti ti-info-circle me-2"></i>

        Event masih berstatus <strong>Draft</strong>.

        Peserta dapat ditambahkan, tetapi email undangan
        belum dikirim sampai event dipublikasikan.

    </div>
@elseif ($event->status === 'published')
    <div class="alert alert-success">

        <i class="ti ti-circle-check me-2"></i>

        Event sudah berstatus <strong>Published</strong>.

        Registrasi peserta dapat dilakukan selama kapasitas tersedia.

    </div>
@else
    <div class="alert alert-warning">

        <i class="ti ti-alert-triangle me-2"></i>

        Event tidak menerima registrasi peserta baru.

    </div>
@endif

{{-- Validation Errors --}}

@if ($participantErrors->isNotEmpty())

    <div class="alert alert-danger">

        <div class="fw-semibold mb-2">
            Registrasi peserta gagal.
        </div>

        <ul class="mb-0">

            @foreach ($participantErrors as $message)
                <li>{{ $message }}</li>
            @endforeach

        </ul>

    </div>

@endif

{{-- Participant List --}}

@include('events.partials.participant-list')
