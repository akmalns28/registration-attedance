<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AttendanceCheckinService
{
    public function __construct(
        private readonly AttendanceQrValidationService $validator,
        private readonly AttendanceCheckinDatePolicy $datePolicy,
    ) {}

    /**
     * @return array{attendance: Attendance, created: bool}
     */
    public function checkIn(string $payload, Event $event, EventDay $day, User $operator): array
    {
        return $this->record($payload, $event, $day, $operator);
    }

    /** @return array{attendance: Attendance, created: bool} */
    public function checkInRegistration(int $registrationId, Event $event, EventDay $day, User $operator): array
    {
        return $this->record($registrationId, $event, $day, $operator);
    }

    /** @return array{attendance: Attendance, created: bool} */
    private function record(string|int $identifier, Event $event, EventDay $day, User $operator): array
    {
        Gate::forUser($operator)->authorize('attendance.scan');
        abort_unless($operator->is_active, 403);

        return DB::transaction(function () use ($identifier, $event, $day, $operator): array {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $lockedDay = EventDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $lockedDay->setRelation('event', $lockedEvent);
            $registrationId = is_int($identifier) ? $identifier : $this->validator->resolve($identifier)->id;
            $registration = EventRegistration::query()->whereKey($registrationId)
                ->with(['event', 'employee', 'externalParticipant'])->lockForUpdate()->firstOrFail();
            $this->validator->validateRegistration($registration, $lockedEvent, $lockedDay);
            $this->datePolicy->ensureAllowed($lockedDay);

            $existing = Attendance::query()->where('event_registration_id', $registration->id)
                ->where('event_day_id', $lockedDay->id)->lockForUpdate()->first();

            $attendance = $existing ?? Attendance::create([
                'event_registration_id' => $registration->id,
                'event_day_id' => $lockedDay->id,
                'checked_in_by' => $operator->id,
                'checked_in_at' => now(config('app.timezone')),
            ]);
            $attendance->setRelation('registration', $registration);
            $attendance->setRelation('eventDay', $lockedDay);
            $attendance->loadMissing('operator');

            return ['attendance' => $attendance, 'created' => $existing === null];
        }, attempts: 3);
    }
}
