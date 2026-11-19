<?php

namespace App\Http\Controllers;

use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Services\AttendanceCheckinService;
use App\Services\AttendanceQrValidationService;
use App\Services\EventDayAttendanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ManualAttendanceController extends Controller
{
    public function search(ManualAttendanceRequest $request, AttendanceQrValidationService $validator): JsonResponse
    {
        $data = $request->validated();
        $event = Event::query()->findOrFail($data['event_id']);
        $day = EventDay::query()->findOrFail($data['event_day_id']);
        $validator->validateContext($event, $day);

        $isEmployee = $data['participant_type'] === EventRegistration::TYPE_EMPLOYEE;
        $relation = $isEmployee ? 'employee' : 'externalParticipant';
        $identifier = $isEmployee ? 'nip' : 'phone';
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';

        $registrations = $event->registrations()->registered()
            ->where('registration_type', $data['participant_type'])
            ->whereNull($isEmployee ? 'external_participant_id' : 'employee_id')
            ->whereHas($relation, fn (Builder $query): Builder => $query->active()
                ->where(fn (Builder $search): Builder => $search->whereRaw("name like ? escape '!'", [$pattern])
                    ->orWhereRaw("{$identifier} like ? escape '!'", [$pattern])))
            ->with($isEmployee ? 'employee.department' : $relation)
            ->withExists(['attendances as checked_in' => fn (Builder $query): Builder => $query->where('event_day_id', $day->id)])
            ->orderBy('id')->limit(15)->get();

        return response()->json(['data' => $registrations->map(fn (EventRegistration $registration): array => [
            'registration_id' => $registration->id,
            'participant_name' => $registration->participant_name,
            'participant_type' => $registration->registration_type,
            'attendance_status' => $registration->checked_in ? 'present' : 'not_present',
            ...($isEmployee ? ['nip' => $registration->employee?->nip, 'department' => $registration->employee?->department?->name]
                : ['phone' => $registration->externalParticipant?->phone]),
        ])])->header('Cache-Control', 'no-store, private');
    }

    public function checkIn(ManualAttendanceRequest $request, AttendanceCheckinService $checkins, EventDayAttendanceService $monitoring): JsonResponse
    {
        $data = $request->validated();
        $event = Event::query()->findOrFail($data['event_id']);
        $day = EventDay::query()->findOrFail($data['event_day_id']);
        $result = $checkins->checkInRegistration((int) $data['registration_id'], $event, $day, $request->user());

        return response()->json([
            'success' => true,
            'created' => $result['created'],
            'message' => $result['created'] ? 'Check-in peserta berhasil.' : 'Peserta sudah check-in.',
            'data' => $monitoring->participant($result['attendance']),
            'summary' => $monitoring->summary($event, $day),
        ], $result['created'] ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }
}
