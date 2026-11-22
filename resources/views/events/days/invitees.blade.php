@php
    use App\Models\EventRegistrationNotification as Notification;

    $canManage = auth()->user()->can('events.update');
    $canPreview = auth()->user()->can('registrations.view');
    $emailStatuses = [
        'pending' => 'Menunggu antrean', 'queued' => 'Dalam antrean', 'processing' => 'Sedang diproses',
        'sent' => 'Terkirim', 'failed' => 'Gagal', 'skipped' => 'Dilewati',
    ];
@endphp

@if ($canManage)
    <p class="text-secondary"><a href="{{ route('events.show', $event) }}#event-participants">Buka daftar peserta event</a> untuk menambah atau mengubah hari undangan.</p>
@endif
<x-ui.compact-filters :id="'day-invitee-filters-'.$day->id" :table="'day-invitations-'.$day->id">
    <x-ui.compact-filter-select id="day-invitees-type" label="Jenis Peserta" data-filter-key="type"
        :options="['' => 'Semua Jenis', 'employee' => 'Employee', 'external' => 'External']" />
    <x-ui.compact-filter-select id="day-invitees-status" label="Status Undangan" data-filter-key="status"
        :options="['' => 'Semua Status', 'invited' => 'Diundang', 'revoked' => 'Undangan dicabut', 'cancelled' => 'Registrasi dibatalkan']" />
</x-ui.compact-filters>
<div class="table-responsive">
    <table id="day-invitations-{{ $day->id }}" data-datatable data-day-invitations-table
        data-export="{{ auth()->user()->can('reports.export') ? 'true' : 'false' }}" data-filename="peserta-diundang-day-{{ $day->day_number }}" class="table table-vcenter w-100">
        <thead>
            <tr>
                <th>Nomor Registrasi</th><th data-priority="1">Nama Peserta</th><th>Jenis Peserta</th><th data-priority="3">Status Undangan</th>
                @can('attendance.view')<th data-priority="2">Kehadiran</th>@endcan
                @if ($canManage || $canPreview)<th>Email Undangan</th><th class="no-sort no-export all">Aksi</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($invitees as $invitee)
                @php
                    $revoked = $invitee->pivot->revoked_at !== null;
                    $canSend = $invitee->isRegistered() && $day->is_active && $event->isPublished();
                    $notification = $canManage || $canPreview ? $invitee->latestInvitation : null;
                @endphp
                <tr data-filter-type="{{ $invitee->isEmployee() ? 'employee' : 'external' }}"
                    data-filter-status="{{ $invitee->isCancelled() ? 'cancelled' : ($revoked ? 'revoked' : 'invited') }}">
                    <td>{{ $invitee->registration_number }}</td>
                    <td>{{ $invitee->participant_name ?? '-' }}</td>
                    <td>{{ $invitee->isEmployee() ? 'Employee' : 'External' }}</td>
                    <td>
                        @if ($invitee->isCancelled())
                            <span class="badge bg-danger-lt">Registrasi dibatalkan</span>
                        @elseif ($revoked)
                            <span class="badge bg-secondary-lt" data-invitation-status>Undangan dicabut</span>
                        @else
                            <span class="badge bg-primary-lt" data-invitation-status>Diundang</span>
                        @endif
                    </td>
                    @can('attendance.view')
                        <td data-invitation-attendance="{{ $invitee->registration_number }}">{{ $invitee->checked_in ? 'Hadir' : 'Belum hadir' }}</td>
                    @endcan
                    @if ($canManage || $canPreview)
                        <td>{{ $notification ? $emailStatuses[$notification->status] : 'Belum dijadwalkan' }}</td>
                        <td class="table-row-actions">
                            <div class="d-flex gap-2 flex-wrap">
                                @if ($canManage && $invitee->isRegistered() && $event->status !== 'cancelled')
                                    <x-ui.action icon="calendar" label="Kelola Hari Undangan" data-bs-toggle="modal"
                                        data-bs-target="#eventDayInvitationModal" aria-haspopup="dialog" aria-controls="eventDayInvitationModal"
                                        :data-invitation-url="route('events.participants.invitations.edit', [$event, $invitee], absolute: false)"
                                        :data-invitation-update-url="route('events.participants.invitations.update', [$event, $invitee], absolute: false)" />
                                @endif
                                @if ($canPreview && $invitee->isRegistered())
                                    <a href="{{ route('events.participants.invitations.preview', [$event, $invitee]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">Preview Email</a>
                                @endif
                                @if ($canManage && $canSend)
                                    <div data-invitation-delivery @if ($revoked) hidden @endif>
                                        @if (! $notification)
                                            <form method="POST" action="{{ route('events.participants.invitations.send', [$event, $invitee]) }}" data-form>
                                                @csrf
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Kirim Undangan</button>
                                            </form>
                                        @elseif (in_array($notification->status, [Notification::STATUS_SENT, Notification::STATUS_SKIPPED], true))
                                            <form method="POST" action="{{ route('notifications.resend', $notification) }}" data-form>
                                                @csrf
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Kirim Ulang</button>
                                            </form>
                                        @elseif (in_array($notification->status, [Notification::STATUS_PENDING, Notification::STATUS_FAILED], true))
                                            <form method="POST" action="{{ route('notifications.retry', $notification) }}" data-form>
                                                @csrf
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Coba Lagi</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
