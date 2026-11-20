<?php

namespace App\Http\Controllers;

use App\Mail\EventRegistrationMail;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Services\EventDayInvitationService;
use App\Services\EventNotificationActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventDayInvitationController extends Controller
{
    public function edit(Event $event, EventRegistration $registration, EventDayInvitationService $invitations): JsonResponse
    {
        abort_unless($registration->isRegistered() && $event->status !== Event::STATUS_CANCELLED, 404);
        $registration->load(['employee', 'externalParticipant', 'invitedDays']);
        $assigned = $registration->invitedDays->modelKeys();
        $available = $invitations->availableDayIds($event);
        $protected = $invitations->protectedDayIds($registration);

        return response()->json([
            'participant_name' => $registration->participant_name ?? 'Peserta',
            'event_name' => $event->title,
            'days' => $event->days()->get()->map(fn (EventDay $day): array => [
                'id' => $day->id,
                'number' => $day->day_number,
                'date' => $day->date->format('d/m/Y'),
                'title' => $day->title,
                'assigned' => in_array($day->id, $assigned, true),
                'available' => in_array($day->id, $available, true),
                'protected' => in_array($day->id, $protected, true),
            ]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Event $event, EventRegistration $registration, EventDayInvitationService $invitations): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'day_ids' => ['sometimes', 'array', 'max:366'],
            'day_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $invitations->replace($event, $registration, $data['day_ids'] ?? []);
        $message = 'Hari undangan peserta diperbarui.';

        if ($request->expectsJson()) {
            $days = $registration->invitedDays()->get(['event_days.id', 'event_days.day_number']);

            return response()->json([
                'message' => $message,
                'day_ids' => $days->modelKeys(),
                'days_label' => $days->map(fn (EventDay $day): string => 'Day '.$day->day_number)->implode(', ') ?: 'Belum ditetapkan',
            ])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->to(route('events.show', $event).'#event-participants')
            ->with('success', $message);
    }

    public function preview(Event $event, EventRegistration $registration): Response
    {
        abort_unless($registration->isRegistered(), 404);
        $registration->setRelation('event', $event);
        $notification = new EventRegistrationNotification([
            'event_id' => $event->id,
            'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
        ]);
        $notification->setRelation('registration', $registration);

        return response((new EventRegistrationMail($notification))->render())
            ->header('Cache-Control', 'private, no-store');
    }

    public function send(Event $event, EventRegistration $registration, EventNotificationActionService $actions): RedirectResponse
    {
        $actions->sendInvitation($event, $registration);

        return redirect()->route('notifications.index', ['event' => $event->uuid])
            ->with('success', 'Undangan tercatat. Lihat status pengiriman pada daftar notifikasi.');
    }
}
