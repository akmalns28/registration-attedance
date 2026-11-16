<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventRegistrationNotification;
use App\Services\EventNotificationActionService;
use App\Services\EventNotificationTransport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\Rule;
use Throwable;

class EventNotificationController extends Controller
{
    public function index(Request $request, EventNotificationTransport $transport): View
    {
        $filters = $request->validate([
            'event' => ['nullable', 'uuid', Rule::exists('events', 'uuid')],
            'status' => ['nullable', Rule::in(EventRegistrationNotification::statuses())],
            'type' => ['nullable', Rule::in(EventRegistrationNotification::types())],
        ]);

        $selectedEvent = ! empty($filters['event']) ? Event::query()->where('uuid', $filters['event'])->firstOrFail() : null;
        $query = EventRegistrationNotification::query()
            ->when($selectedEvent, fn ($query) => $query->where('event_id', $selectedEvent->id));
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $notifications = $query
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('notification_type', $type))
            ->with(['event', 'registration.employee', 'registration.externalParticipant'])
            ->orderByDesc('id')->paginate(25)->withQueryString();

        $delivery = $transport->status();
        $queueSize = null;

        try {
            $queueSize = Queue::connection($delivery['connection'])->size($delivery['queue']);
        } catch (Throwable) {
            $delivery['queue_error'] = 'Antrean tidak dapat diakses. Periksa koneksi worker.';
        }

        return view('notifications.index', [
            'notifications' => $notifications,
            'events' => Event::query()->orderByDesc('start_date')->get(['id', 'uuid', 'title', 'status']),
            'selectedEvent' => $selectedEvent,
            'filters' => $filters,
            'counts' => $counts,
            'delivery' => $delivery,
            'queueSize' => $queueSize,
        ]);
    }

    public function retry(EventRegistrationNotification $notification, EventNotificationActionService $actions): RedirectResponse
    {
        $retried = $actions->retry($notification);

        if ($retried->fresh()->status === EventRegistrationNotification::STATUS_PENDING) {
            return redirect()->route('notifications.index', ['event' => $notification->event->uuid])
                ->with('error', 'Notifikasi tersimpan tetapi belum masuk antrean. Periksa detail kegagalan pengiriman.');
        }

        return redirect()->route('notifications.index', ['event' => $notification->event->uuid])
            ->with('success', 'Notifikasi masuk antrean pengiriman. Status akan diperbarui setelah worker memprosesnya.');
    }

    public function resend(EventRegistrationNotification $notification, EventNotificationActionService $actions): RedirectResponse
    {
        $resent = $actions->resend($notification);

        if ($resent->fresh()->status === EventRegistrationNotification::STATUS_PENDING) {
            return redirect()->route('notifications.index', ['event' => $notification->event->uuid])
                ->with('error', 'Permintaan kirim ulang tersimpan tetapi antrean belum tersedia. Periksa detail pengiriman.');
        }

        return redirect()->route('notifications.index', ['event' => $notification->event->uuid])
            ->with('success', 'Permintaan kirim ulang tercatat. Periksa status pengiriman pada daftar notifikasi.');
    }

    public function invitations(Event $event, EventNotificationActionService $actions): RedirectResponse
    {
        $counts = $actions->invitations($event);

        return redirect()->route('notifications.index', ['event' => $event->uuid])
            ->with('success', sprintf('%d undangan dijadwalkan. %d registrasi tidak memerlukan pengiriman baru. Periksa status pengiriman pada tabel.', $counts['queued'], $counts['skipped']));
    }
}
