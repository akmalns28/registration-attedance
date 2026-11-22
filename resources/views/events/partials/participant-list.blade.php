{{-- Participant List --}}

@php
    $canViewInvitations = auth()->user()->can('registrations.view');
@endphp

<div class="card participant-list">

    <div class="card-header">

        <div>
            <h3 class="card-title">
                Daftar Peserta
            </h3>

        </div>

        <div class="card-actions">

            <span class="badge bg-primary-lt">
                {{ $event->registered_participants_count }}
                Registered
            </span>

            <span class="badge bg-secondary-lt ms-1">
                {{ $event->cancelled_registrations_count }}
                Cancelled
            </span>

        </div>

    </div>

    <div class="card-body">

        <x-ui.compact-filters id="event-participant-filters" table="eventParticipantsTable">
            <x-ui.compact-filter-select id="event-participants-type" label="Jenis Peserta" data-filter-key="type"
                :options="['' => 'Semua Jenis', 'employee' => 'Employee', 'external' => 'External']" />
            <x-ui.compact-filter-select id="event-participants-status" label="Status Registrasi" data-filter-key="status"
                :options="['' => 'Semua Status', 'registered' => 'Registered', 'cancelled' => 'Cancelled']" />
        </x-ui.compact-filters>
        <div class="table-responsive">

            <table id="eventParticipantsTable" class="table table-vcenter table-hover w-100" data-datatable
                data-export="{{ auth()->user()->can('reports.export') ? 'true' : 'false' }}" data-filename="event-participants">

                <thead>
                    <tr>
                        <th class="no-sort no-export">
                            No.
                        </th>

                        <th data-priority="3">
                            Registration Number
                        </th>

                        <th class="no-sort no-export">
                            QR Code
                        </th>

                        <th class="all" data-priority="1">
                            Nama Peserta
                        </th>

                        <th>
                            Type
                        </th>

                        <th>
                            Department / Organization
                        </th>

                        <th data-priority="2">
                            Status
                        </th>

                        <th>Hari Undangan</th>

                        <th>
                            Registered At
                        </th>

                        <th class="no-sort no-export text-end all" data-priority="1">
                            Action
                        </th>
                    </tr>
                </thead>

                <tbody>

                    @foreach ($registrations as $registration)
                        @php
                            $isEmployee = $registration->isEmployee();

                            $participant = $isEmployee ? $registration->employee : $registration->externalParticipant;

                            $participantName = $participant?->name ?? 'Unknown Participant';

                            $participantInfo = $isEmployee
                                ? $registration->employee?->department?->name ?? '-'
                                : $registration->externalParticipant?->organization ?? '-';

                            $isRegistered = $registration->isRegistered();
                        @endphp

                        <tr data-filter-type="{{ $isEmployee ? 'employee' : 'external' }}" data-filter-status="{{ $registration->status }}">

                            {{-- No --}}
                            <td>
                                {{ $loop->iteration }}
                            </td>

                            {{-- Registration Number --}}
                            <td>

                                <span class="font-monospace small">
                                    {{ $registration->registration_number }}
                                </span>

                            </td>

                            {{-- Registration QR Code --}}
                            <td>
                                <a href="{{ route('events.participants.qr', [$event, $registration]) }}" target="_blank" rel="noopener" title="Buka QR ukuran penuh">
                                    <img src="{{ route('events.participants.qr', [$event, $registration]) }}" alt="QR Code registrasi {{ $registration->registration_number }}"
                                        width="111" height="111" loading="lazy" class="border rounded bg-white p-1" style="image-rendering: pixelated;">
                                </a>
                                <x-ui.action icon="download" label="Unduh QR PNG" class="mt-1" :href="route('events.participants.qr', ['event' => $event, 'registration' => $registration, 'download' => 1])" />
                                <details class="small mt-1">
                                    <summary class="text-secondary">Nilai QR</summary>
                                    <code class="d-block text-break mt-1" style="max-width: 180px;">{{ app(\App\Services\RegistrationQrCodeService::class)->payload($registration) }}</code>
                                </details>
                            </td>

                            {{-- Participant --}}
                            <td>

                                <div class="fw-semibold">
                                    {{ $participantName }}
                                </div>

                                @if ($isEmployee)
                                    <div class="text-secondary small">
                                        NIP:
                                        {{ $registration->employee?->nip ?? '-' }}
                                    </div>
                                @else
                                    @if ($registration->externalParticipant?->email)
                                        <div class="text-secondary small">
                                            {{ $registration->externalParticipant->email }}
                                        </div>
                                    @endif
                                @endif

                            </td>

                            {{-- Type --}}
                            <td>

                                @if ($isEmployee)
                                    <span class="badge bg-blue-lt">
                                        <x-ui.icon name="user" class="me-1" />
                                        Employee
                                    </span>
                                @else
                                    <span class="badge bg-purple-lt">
                                        <x-ui.icon name="users" class="me-1" />
                                        External
                                    </span>
                                @endif

                            </td>

                            {{-- Department / Organization --}}
                            <td>
                                {{ $participantInfo }}
                            </td>

                            {{-- Status --}}
                            <td>

                                @if ($isRegistered)
                                    <span class="badge bg-success-lt">
                                        <x-ui.icon name="circle-check" class="me-1" />
                                        Registered
                                    </span>
                                @else
                                    <span class="badge bg-danger-lt">
                                        <x-ui.icon name="circle-x" class="me-1" />
                                        Cancelled
                                    </span>

                                    @if ($registration->cancelled_at)
                                        <div class="text-secondary small mt-1">
                                            {{ $registration->cancelled_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                @endif

                            </td>

                            <td data-invitation-summary>
                                {{ $registration->invitedDays->map(fn ($day) => 'Day '.$day->day_number)->implode(', ') ?: 'Belum ditetapkan' }}
                            </td>

                            {{-- Registered At --}}
                            <td data-order="{{ $registration->registered_at?->timestamp ?? 0 }}">

                                {{ $registration->registered_at ? $registration->registered_at->format('d/m/Y H:i') : '-' }}

                            </td>

                            {{-- Actions --}}
                            <td class="text-end text-nowrap table-row-actions">
                                <div class="participant-row-actions">
                                @if ($isRegistered && $canViewInvitations)
                                    <x-ui.action icon="file-type-pdf" label="Download Invitation PDF"
                                        :href="route('events.participants.invitation', [$event, $registration])" />
                                @endif

                                @can('events.update')
                                    @if ($isRegistered)
                                        @if ($event->status !== 'cancelled')
                                            <x-ui.action icon="calendar" label="Kelola Hari Undangan" data-bs-toggle="modal"
                                                data-bs-target="#eventDayInvitationModal" aria-haspopup="dialog" aria-controls="eventDayInvitationModal"
                                                :data-invitation-url="route('events.participants.invitations.edit', [$event, $registration], absolute: false)"
                                                :data-invitation-update-url="route('events.participants.invitations.update', [$event, $registration], absolute: false)" />
                                        @endif
                                        {{-- Cancel Registration --}}
                                        <x-ui.action icon="x" label="Batalkan Registrasi" variant="danger" data-bs-toggle="modal"
                                            data-bs-target="#cancelRegistrationModal"
                                            data-cancel-url="{{ route('events.participants.destroy', [$event, $registration]) }}"
                                            data-participant-name="{{ $participantName }}" />
                                    @elseif (in_array($event->status, ['draft', 'published'], true) && $participant?->is_active)
                                        {{-- Reactivate Registration --}}
                                        <x-ui.action icon="refresh" label="Aktifkan Kembali Registrasi" variant="success" data-bs-toggle="modal"
                                            data-bs-target="#reactivateRegistrationModal"
                                            data-reactivate-url="{{ route('events.participants.reactivate', [$event, $registration]) }}"
                                            data-participant-name="{{ $participantName }}" />
                                    @else
                                        <span class="text-secondary small">
                                            Tidak dapat diaktifkan
                                        </span>
                                    @endif
                                @elseif (! $isRegistered || ! $canViewInvitations)
                                    <span class="text-secondary">
                                        -
                                    </span>
                                @endcan

                                </div>
                            </td>

                        </tr>
                    @endforeach

                </tbody>

            </table>

        </div>

        <p class="text-secondary small mt-3 mb-0">
            QR berisi token registrasi RA1. Gunakan pembaca QR yang mendukung teks; iPhone Camera tidak dapat membuka token ini.
            Klik gambar untuk ukuran penuh atau unduh PNG.
        </p>

        <div class="text-secondary small mt-3">

            <x-ui.icon name="info-circle" class="me-1" />

            Registrasi yang dibatalkan tetap tersimpan.
            Gunakan tombol Reactivate untuk mengaktifkan kembali peserta
            selama event masih menerima registrasi dan kapasitas tersedia.

        </div>

    </div>

</div>
