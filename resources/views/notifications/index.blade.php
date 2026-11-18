@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    @php
        $statusLabels = ['pending' => 'Menunggu', 'queued' => 'Dalam antrean', 'processing' => 'Diproses', 'sent' => 'Terkirim', 'failed' => 'Gagal', 'skipped' => 'Dilewati'];
        $statusColors = ['pending' => 'secondary', 'queued' => 'azure', 'processing' => 'blue', 'sent' => 'success', 'failed' => 'danger', 'skipped' => 'warning'];
        $typeLabels = ['invitation' => 'Undangan', 'cancellation' => 'Pembatalan', 'reactivation' => 'Reaktivasi', 'reminder' => 'Pengingat'];
    @endphp

    <x-ui.page-header title="Notifications" subtitle="Pantau pengiriman undangan dan notifikasi peserta.">
        <x-slot:actions>
            <x-ui.button :href="route('notifications.index', request()->only('event', 'status', 'type'))" outline>
                <x-ui.icon name="refresh" class="me-2" /> Refresh status
            </x-ui.button>
            @if ($selectedEvent)
                <x-ui.button :href="route('events.show', $selectedEvent).'#event-participants'" variant="secondary" outline>
                    Daftar Peserta
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert variant="danger" :dismissible="false">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if (! $delivery['ready'])
        <x-ui.alert variant="warning" title="Pengiriman email belum siap" :dismissible="false">
            {{ $delivery['problem'] }}
        </x-ui.alert>
    @endif

    <div class="row row-cards mb-4">
        <div class="col-6 col-lg-3"><x-ui.stat-card title="Total notifikasi" :value="$counts->sum()" /></div>
        <div class="col-6 col-lg-3"><x-ui.stat-card title="Terkirim" :value="$counts['sent'] ?? 0" color="success" /></div>
        <div class="col-6 col-lg-3"><x-ui.stat-card title="Menunggu / diproses" :value="($counts['pending'] ?? 0) + ($counts['queued'] ?? 0) + ($counts['processing'] ?? 0)" /></div>
        <div class="col-6 col-lg-3"><x-ui.stat-card title="Gagal / dilewati" :value="($counts['failed'] ?? 0) + ($counts['skipped'] ?? 0)" color="danger" /></div>
    </div>

    @can('events.update')
        <x-ui.card title="Pengiriman email" class="mb-4">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <span class="badge {{ $delivery['ready'] ? 'bg-success-lt' : 'bg-warning-lt' }}">
                    {{ $delivery['ready'] ? 'Email terkonfigurasi' : 'Perlu konfigurasi email' }}
                </span>
                <span class="text-secondary">Mailer: {{ $delivery['mailer'] }}</span>
                <span class="text-secondary">Antrean: {{ $delivery['queue'] }}</span>
                <span class="text-secondary">{{ $queueSize === null ? 'Antrean tidak dapat diakses' : $queueSize.' job menunggu / diproses' }}</span>
            </div>
            <p class="text-secondary small mt-3 mb-0">
                Status Terkirim dicatat setelah server email menerima pesan. Pesan yang masuk antrean diproses oleh worker pengiriman.
                Catatan lama tanpa informasi transport dapat dibuat saat mailer masih memakai log; gunakan Kirim ulang jika peserta belum menerima email.
            </p>
            @if ($queueSize > 0)
                <details class="mt-3 small">
                    <summary>Menjalankan worker pengiriman</summary>
                    <code class="d-block mt-2 text-break">php artisan queue:work {{ $delivery['connection'] }} --queue={{ $delivery['queue'] }} --tries={{ config('event_notifications.tries', 3) }} --timeout={{ config('event_notifications.timeout', 60) }}</code>
                </details>
            @endif
            @if (isset($delivery['queue_error']))
                <div class="text-danger mt-2">{{ $delivery['queue_error'] }}</div>
            @endif
        </x-ui.card>
    @endcan

    <x-ui.card class="mb-4">
        <form method="GET" action="{{ route('notifications.index') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="notificationEvent" class="form-label">Event</label>
                <select id="notificationEvent" name="event" class="form-select">
                    <option value="">Semua event</option>
                    @foreach ($events as $event)
                        <option value="{{ $event->uuid }}" @selected(($filters['event'] ?? null) === $event->uuid)>{{ $event->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="notificationStatus" class="form-label">Status</label>
                <select id="notificationStatus" name="status" class="form-select">
                    <option value="">Semua status</option>
                    @foreach ($statusLabels as $status => $label)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="notificationType" class="form-label">Jenis</label>
                <select id="notificationType" name="type" class="form-select">
                    <option value="">Semua jenis</option>
                    @foreach ($typeLabels as $type => $label)
                        <option value="{{ $type }}" @selected(($filters['type'] ?? null) === $type)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2"><x-ui.button type="submit" class="w-100">Terapkan filter</x-ui.button></div>
        </form>

        @can('events.update')
            @if ($selectedEvent)
                <div class="d-flex flex-wrap align-items-center gap-3 border-top pt-3 mt-3">
                    <form method="POST" action="{{ route('events.notifications.invitations', $selectedEvent) }}" data-form>
                        @csrf
                        <x-ui.button type="submit" :disabled="! $delivery['ready'] || ! $selectedEvent->isPublished()">
                            <x-ui.icon name="send" class="me-2" /> Kirim undangan yang belum dikirim
                        </x-ui.button>
                    </form>
                    <span class="text-secondary small">Undangan yang sudah ada tidak dibuat ulang. Catatan Terkirim dapat dikirim ulang secara terpisah.</span>
                </div>
            @endif
        @endcan
    </x-ui.card>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Riwayat Notifikasi</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter table-hover">
                <thead>
                    <tr>
                        <th>Peserta / registrasi</th>
                        <th>Event</th>
                        <th>Jenis / penerima</th>
                        <th>Status</th>
                        <th>Percobaan</th>
                        <th>Waktu</th>
                        @can('events.update')<th class="text-end">Tindakan</th>@endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notifications as $notification)
                        @php
                            $registration = $notification->registration;
                            $participant = $registration?->isEmployee() ? $registration->employee : $registration?->externalParticipant;
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $participant?->name ?? 'Peserta tidak ditemukan' }}</div>
                                <div class="small text-secondary font-monospace">{{ $registration?->registration_number ?? '-' }}</div>
                            </td>
                            <td>
                                @if ($notification->event)
                                    <a href="{{ route('events.show', $notification->event).'#event-participants' }}">{{ $notification->event->title }}</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                <div>{{ $typeLabels[$notification->notification_type] ?? $notification->notification_type }}</div>
                                <div class="text-secondary small">{{ $notification->recipient_email ?: 'Email belum tersedia' }}</div>
                            </td>
                            <td>
                                <span class="badge bg-{{ $statusColors[$notification->status] ?? 'secondary' }}-lt">{{ $statusLabels[$notification->status] ?? $notification->status }}</span>
                                @can('events.update')
                                    @if ($notification->last_error)
                                        <details class="small mt-2">
                                            <summary class="text-danger">Detail pengiriman</summary>
                                            <div class="text-break mt-1" style="max-width: 320px;">{{ $notification->last_error }}</div>
                                        </details>
                                    @endif
                                    @if ($notification->metadata['delivery_transport'] ?? null)
                                        <div class="small text-secondary mt-1">Transport: {{ $notification->metadata['delivery_transport'] }}</div>
                                    @endif
                                @endcan
                            </td>
                            <td>{{ $notification->attempts }} / {{ config('event_notifications.tries', 3) }}</td>
                            <td class="small text-secondary">
                                <div>Dibuat: {{ $notification->created_at->format('d/m/Y H:i') }}</div>
                                @if ($notification->sent_at)<div>Terkirim: {{ $notification->sent_at->format('d/m/Y H:i') }}</div>@endif
                                @if ($notification->last_attempt_at)<div>Dicoba: {{ $notification->last_attempt_at->format('d/m/Y H:i') }}</div>@endif
                            </td>
                            @can('events.update')
                                <td class="text-end">
                                    @if (in_array($notification->status, ['sent', 'skipped'], true))
                                        <x-ui.action icon="send" label="Kirim Ulang Notifikasi" variant="primary" data-bs-toggle="modal" data-bs-target="#resendNotification{{ $notification->id }}" :disabled="! $delivery['ready']" />
                                    @else
                                        <form method="POST" action="{{ route('notifications.retry', $notification) }}" data-form>
                                            @csrf
                                            <x-ui.action type="submit" icon="refresh" label="Coba Lagi Pengiriman" variant="primary" :disabled="! $delivery['ready'] || $notification->attempts >= config('event_notifications.tries', 3)" />
                                        </form>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="{{ auth()->user()->can('events.update') ? 7 : 6 }}" class="text-center text-secondary py-5">Belum ada notifikasi. Pilih event Published untuk menjadwalkan undangan peserta.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $notifications->links() }}</div>
    </div>

    @can('events.update')
        @foreach ($notifications->whereIn('status', ['sent', 'skipped']) as $notification)
            <div class="modal modal-blur fade" id="resendNotification{{ $notification->id }}" tabindex="-1" aria-labelledby="resendNotificationTitle{{ $notification->id }}" aria-hidden="true">
                <div class="modal-dialog modal-sm modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="resendNotificationTitle{{ $notification->id }}">Kirim ulang notifikasi?</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                        </div>
                        <div class="modal-body">
                            Email baru akan dikirim ke alamat peserta yang aktif saat ini. Jika email sebelumnya sudah diterima, peserta akan menerima email lagi.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                            <form method="POST" action="{{ route('notifications.resend', $notification) }}" data-form>
                                @csrf
                                <x-ui.button type="submit">Kirim ulang</x-ui.button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    @endcan
@endsection
