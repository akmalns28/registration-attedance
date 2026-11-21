{{-- Participant Summary --}}

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h3 class="mb-1">
            Event Participants
        </h3>

    </div>

    <div class="btn-list">
        <a href="{{ route('notifications.index', ['event' => $event->uuid]) }}" class="btn btn-outline-primary">
            <x-ui.icon name="mail" class="me-2" /> Email peserta
        </a>
        @can('registrations.manage')
            @php
                $exportableRegistrations = $registrations->filter(fn ($registration) => $registration->isRegistered()
                    && $registration->invitedDays->contains('is_active', true));
                $exportableCounts = $exportableRegistrations->countBy('registration_type');
            @endphp
            <div class="dropdown">
                <button type="button" class="btn btn-outline-primary dropdown-toggle" id="invitation-export-menu"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <x-ui.icon name="download" class="me-2" /> Export Invitations
                </button>
                <div class="dropdown-menu dropdown-menu-end" aria-labelledby="invitation-export-menu">
                    @foreach ([
                        'all' => ['Semua peserta', $exportableRegistrations->count()],
                        'employee' => ['Employee', $exportableCounts->get('employee', 0)],
                        'external' => ['External', $exportableCounts->get('external', 0)],
                    ] as $type => [$label, $count])
                        <a class="dropdown-item" href="{{ route('events.participants.invitations.export', ['event' => $event, 'participant_type' => $type]) }}">
                            {{ $label }} ({{ number_format($count, 0, ',', '.') }})
                        </a>
                    @endforeach
                </div>
            </div>
        @endcan
        @if ($canAddParticipants)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addParticipantsModal">
                <x-ui.icon name="users-plus" class="me-2" /> Add Participants
            </button>
        @endif
    </div>

</div>

{{-- Summary Cards --}}

<div class="row row-cards mb-4">

    <div class="col-6 col-lg-3">
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

    <div class="col-6 col-lg-3">
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

    <div class="col-6 col-lg-3">
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

    <div class="col-6 col-lg-3">
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

        <x-ui.icon name="info-circle" class="me-2" />

        Event masih berstatus <strong>Draft</strong>.

        Peserta dapat ditambahkan, tetapi email undangan
        belum dikirim sampai event dipublikasikan.

    </div>
@elseif ($event->status === 'published')
    <div class="alert alert-success">

        <x-ui.icon name="circle-check" class="me-2" />

        Event sudah berstatus <strong>Published</strong>.

        Registrasi peserta dapat dilakukan selama kapasitas tersedia.

    </div>
@else
    <div class="alert alert-warning">

        <x-ui.icon name="alert-triangle" class="me-2" />

        Event tidak menerima registrasi peserta baru.

    </div>
@endif

{{-- Validation Errors --}}

@if ($errors->invitations->any())
    <div class="alert alert-danger" role="alert">
        {{ $errors->invitations->first() }}
    </div>
@endif

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
