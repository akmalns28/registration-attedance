<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventInvitationExport;
use App\Models\EventRegistration;
use App\Services\EventAssetStorage;
use App\Services\EventInvitationExportQueue;
use App\Services\EventInvitationExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

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

    public function bulk(Request $request, Event $event, EventInvitationExportQueue $queue): Response|RedirectResponse|JsonResponse
    {
        if ($request->isMethod('HEAD')) {
            return response('', 200, EventAssetStorage::PRIVATE_HEADERS);
        }

        try {
            $filters = $request->validate([
                'participant_type' => ['sometimes', 'required', Rule::in(['all', ...EventRegistration::types()])],
            ], [
                'participant_type.*' => 'Pilih semua peserta, Employee, atau External.',
            ]);

            $participantType = $filters['participant_type'] ?? 'all';
            try {
                $export = $queue->prepare($event, $request->user(), $participantType);
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                Log::error('Invitation export could not be prepared.', [
                    'event_id' => $event->id,
                    'participant_type' => $participantType,
                    'exception' => $exception,
                ]);

                throw ValidationException::withMessages(['export' => 'Ekspor undangan gagal disiapkan. Silakan coba lagi.'])->status(500);
            }
            if ($export instanceof EventInvitationExport) {
                if ($request->expectsJson()) {
                    return $this->statusResponse($event, $export, 202);
                }

                return redirect()->to(route('events.show', $event).'#event-participants');
            }

            return $this->invitations->downloadPdf($event, $export);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('invitations')->redirectTo(route('events.show', $event).'#event-participants');
        }
    }

    public function status(Request $request, Event $event, EventInvitationExport $invitationExport): JsonResponse
    {
        $this->authorizeExport($request, $invitationExport);

        return $this->statusResponse($event, $invitationExport);
    }

    public function archive(Request $request, Event $event, EventInvitationExport $invitationExport, EventAssetStorage $assets): BinaryFileResponse
    {
        $this->authorizeExport($request, $invitationExport);
        abort_unless($invitationExport->isDownloadable(), 404);

        return $assets->response($invitationExport->archivePath(), 'application/zip', $this->invitations->eventSlug($event).'-invitations.zip');
    }

    private function statusResponse(Event $event, EventInvitationExport $export, int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'id' => $export->id,
            'status' => $export->status,
            'participant_count' => $export->participant_count,
            'processed_count' => $export->processed_count,
            'status_url' => route('events.participants.invitations.export.status', [$event, $export]),
            'download_url' => $export->isDownloadable() ? route('events.participants.invitations.export.download', [$event, $export]) : null,
        ], $httpStatus, EventAssetStorage::PRIVATE_HEADERS);
    }

    private function authorizeExport(Request $request, EventInvitationExport $export): void
    {
        abort_unless((int) $export->requested_by === (int) $request->user()->id, 404);
        abort_if($export->expires_at?->isPast(), 404);
    }
}
