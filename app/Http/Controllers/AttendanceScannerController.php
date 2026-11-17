<?php

namespace App\Http\Controllers;

use App\Http\Requests\Attendance\ValidateAttendanceQrRequest;
use App\Models\Event;
use App\Models\EventDay;
use App\Services\AttendanceQrValidationService;
use App\Services\AttendanceScannerContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class AttendanceScannerController extends Controller
{
    public function __construct(private readonly AttendanceScannerContext $scannerContext) {}

    public function index(): View
    {
        return view('attendance.scanner', $this->scannerContext->viewData());
    }

    public function forEvent(Event $event): View
    {
        abort_unless($event->isPublished(), 403, 'QR Scanner hanya tersedia untuk Event Published.');

        return view('attendance.scanner', $this->scannerContext->viewData($event));
    }

    public function validate(ValidateAttendanceQrRequest $request, AttendanceQrValidationService $validator): JsonResponse
    {
        $data = $request->validated();
        $event = Event::query()->findOrFail($data['event_id']);
        $day = EventDay::query()->findOrFail($data['event_day_id']);
        $registration = $validator->validate($data['qr_code'], $event, $day);

        return response()->json([
            'success' => true,
            'message' => 'Registrasi peserta valid. Kehadiran belum dicatat.',
            'data' => [
                'registration_number' => $registration->registration_number,
                'participant_name' => $registration->participant_name,
                'participant_type' => $registration->registration_type,
                'event_name' => $event->title,
                'event_day' => $day->date->toDateString(),
                'registration_status' => $registration->status,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
