<?php

namespace App\Http\Controllers;

use App\Http\Requests\Attendance\ValidateAttendanceQrRequest;
use App\Models\Event;
use App\Models\EventDay;
use App\Services\AttendanceCheckinService;
use App\Services\EventDayAttendanceService;
use Illuminate\Http\JsonResponse;

class AttendanceCheckinController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ValidateAttendanceQrRequest $request, AttendanceCheckinService $checkins, EventDayAttendanceService $monitoring): JsonResponse
    {
        $data = $request->validated();
        $event = Event::query()->findOrFail($data['event_id']);
        $day = EventDay::query()->findOrFail($data['event_day_id']);
        $result = $checkins->checkIn($data['qr_code'], $event, $day, $request->user());

        return response()->json([
            'success' => true,
            'created' => $result['created'],
            'message' => $result['created'] ? 'Check-in peserta berhasil.' : 'Peserta sudah check-in.',
            'data' => $monitoring->participant($result['attendance']),
            'summary' => $monitoring->summary($event, $day),
        ], $result['created'] ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }
}
