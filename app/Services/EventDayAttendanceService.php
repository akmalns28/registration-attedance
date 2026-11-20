<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventDayAttendanceService
{
    /**
     * Counts use current invitations; attendance history is queried separately.
     *
     * @return array{invited: int, active_registrations: int, checked_in: int, not_checked_in: int, percentage: float}
     */
    public function summary(Event $event, EventDay $day): array
    {
        abort_unless((int) $day->event_id === (int) $event->id, 404);
        $counts = $this->countsByDay($event, $day)->get($day->id);
        $active = (int) ($counts?->invited ?? 0);
        $checkedIn = (int) ($counts?->checked_in ?? 0);

        return [
            'invited' => $active,
            'active_registrations' => $active,
            'checked_in' => $checkedIn,
            'not_checked_in' => max(0, $active - $checkedIn),
            'percentage' => $active === 0 ? 0.0 : round($checkedIn / $active * 100, 2),
        ];
    }

    public function invitations(): QueryBuilder
    {
        return DB::table('event_day_invitations')
            ->join('event_registrations', 'event_registrations.id', '=', 'event_day_invitations.event_registration_id')
            ->join('event_days', fn (JoinClause $join): JoinClause => $join
                ->on('event_days.id', '=', 'event_day_invitations.event_day_id')
                ->on('event_days.event_id', '=', 'event_registrations.event_id'))
            ->whereNull('event_day_invitations.revoked_at')
            ->where('event_registrations.status', EventRegistration::STATUS_REGISTERED);
    }

    /** @return Collection<int, object{invited: int, checked_in: int}> */
    public function countsByDay(Event $event, ?EventDay $day = null): Collection
    {
        return $this->invitations()->where('event_days.event_id', $event->id)
            ->when($day, fn (QueryBuilder $query, EventDay $day): QueryBuilder => $query->where('event_days.id', $day->id))
            ->leftJoin('attendances', fn (JoinClause $join): JoinClause => $join
                ->on('attendances.event_registration_id', '=', 'event_registrations.id')
                ->on('attendances.event_day_id', '=', 'event_days.id'))
            ->select('event_days.id')
            ->selectRaw('count(distinct event_registrations.id) as invited, count(distinct attendances.event_registration_id) as checked_in')
            ->groupBy('event_days.id')->get()->keyBy('id');
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
