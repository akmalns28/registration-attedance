<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventParticipant\StoreEventParticipantRequest;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\EventRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EventParticipantController extends Controller
{
    public function __construct(private readonly EventRegistrationService $registrationService) {}

    /**
     * Register employees or external participants.
     */
    public function store(StoreEventParticipantRequest $request, Event $event): RedirectResponse
    {
        $data = $request->validated();
        $dayIds = match ($data['day_selection'] ?? null) {
            'all' => null,
            'selected' => $data['day_ids'],
            default => [],
        };

        try {
            $result = match ($data['participant_type']) {
                EventRegistration::TYPE_EMPLOYEE => $this->registrationService->registerEmployees($event, $data['participant_ids'], $dayIds),

                EventRegistration::TYPE_EXTERNAL => $this->registrationService->registerExternals($event, $data['participant_ids'], $dayIds),
            };
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('events.show', $event).'#event-participants');
        }

        $message = sprintf('Registrasi selesai. %d peserta baru, %d diaktifkan kembali, %d sudah terdaftar.', $result['created'], $result['reactivated'], $result['skipped']);

        return redirect()
            ->to(route('events.show', $event).'#event-participants')
            ->with('success', $message);
    }

    /**
     * Cancel participant registration.
     */
    public function destroy(Event $event, EventRegistration $registration): RedirectResponse
    {
        Gate::authorize('events.update');

        /*
        |--------------------------------------------------------------------------
        | Validate Registration Ownership
        |--------------------------------------------------------------------------
        */

        abort_unless($registration->event_id === $event->id, 404);

        /*
        |--------------------------------------------------------------------------
        | Cancel Registration
        |--------------------------------------------------------------------------
        */

        $this->registrationService->cancel($registration);

        return redirect()
            ->to(route('events.show', $event).'#event-participants')
            ->with('success', 'Registrasi peserta berhasil dibatalkan.');
    }

    /**
     * Reactivate a cancelled event registration.
     */
    public function reactivate(Event $event, EventRegistration $registration): RedirectResponse
    {
        Gate::authorize('events.update');

        /*
    |--------------------------------------------------------------------------
    | Validate Registration Ownership
    |--------------------------------------------------------------------------
    */

        abort_unless($registration->event_id === $event->id, 404);

        /*
    |--------------------------------------------------------------------------
    | Reactivate Registration
    |--------------------------------------------------------------------------
    */

        try {
            $this->registrationService->reactivate($registration);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('events.show', $event).'#event-participants');
        }

        return redirect()
            ->to(route('events.show', $event).'#event-participants')
            ->with('success', 'Registrasi peserta berhasil diaktifkan kembali.');
    }
}
