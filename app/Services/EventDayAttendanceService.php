<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use Illuminate\Database\Eloquent\Builder;

class EventDayAttendanceService
{
    /**
     * Counters use active registrations; the history list also preserves cancelled registrations.
     *
     * @return array{active_registrations: int, checked_in: int, not_checked_in: int, percentage: float}
     */
    public function summary(Event $event, EventDay $day): array
    {
        abort_unless((int) $day->event_id === (int) $event->id, 404);
        $active = $event->registrations()->registered()->count();
        $checkedIn = Attendance::query()->where('event_day_id', $day->id)
            ->whereHas('registration', fn (Builder $query): Builder => $query->where('event_id', $event->id)->registered())
            ->distinct()->count('event_registration_id');

        return [
            'active_registrations' => $active,
            'checked_in' => $checkedIn,
            'not_checked_in' => max(0, $active - $checkedIn),
            'percentage' => $active === 0 ? 0.0 : round($checkedIn / $active * 100, 2),
        ];
    }

    /**
     * @return array<int, array<string, string|bool>>
     */
    public function attendees(Event $event, EventDay $day): array
    {
        abort_unless((int) $day->event_id === (int) $event->id, 404);

        return Attendance::query()->where('event_day_id', $day->id)
            ->whereHas('registration', fn (Builder $query): Builder => $query->where('event_id', $event->id))
            ->with(['registration.employee', 'registration.externalParticipant', 'operator', 'eventDay.event'])
            ->orderByDesc('checked_in_at')->orderByDesc('id')->get()
            ->map(fn (Attendance $attendance): array => $this->participant($attendance))->all();
    }

    /**
     * @return array<string, string|bool>
     */
    public function participant(Attendance $attendance): array
    {
        return [
            'registration_number' => $attendance->registration->registration_number,
            'participant_name' => $attendance->registration->participant_name ?? '-',
            'participant_type' => $attendance->registration->registration_type,
            'event_name' => $attendance->eventDay->event->title,
            'event_day' => $attendance->eventDay->date->toDateString(),
            'registration_status' => $attendance->registration->status,
            'checked_in_at' => $attendance->checked_in_at->toIso8601String(),
            'checkin_time' => $attendance->checked_in_at->format('H:i:s'),
            'operator_name' => $attendance->operator?->name ?? '-',
            'attendance_status' => 'present',
        ];
    }
}
