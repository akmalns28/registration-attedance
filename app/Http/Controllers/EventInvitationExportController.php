<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\EventInvitationExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventInvitationExportController extends Controller
{
    public function __construct(private readonly EventInvitationExportService $invitations) {}

    public function download(Event $event, EventRegistration $registration): Response
    {
        try {
            return $this->invitations->download($event, $registration);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('invitations')->redirectTo(route('events.show', $event).'#event-participants');
        }
    }

    public function bulk(Request $request, Event $event): StreamedResponse
    {
        try {
            $filters = $request->validate([
                'participant_type' => ['sometimes', 'required', Rule::in(['all', ...EventRegistration::types()])],
            ], [
                'participant_type.*' => 'Pilih semua peserta, Employee, atau External.',
            ]);

            return $this->invitations->bulk($event, $filters['participant_type'] ?? 'all');
        } catch (ValidationException $exception) {
            throw $exception->errorBag('invitations')->redirectTo(route('events.show', $event).'#event-participants');
        }
    }
}
