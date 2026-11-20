<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class AnalyticService
{
    public function __construct(private readonly EventDayAttendanceService $attendance) {}

    /** @return Collection<int, Event> */
    public function events(): Collection
    {
        return Event::query()->orderByDesc('start_date')->orderBy('id')->get(['id', 'uuid', 'title']);
    }

    /**
     * Dates select Event Day opportunities, including inactive days and historical events.
     * Events without opportunities are excluded only when a day/date filter is applied.
     *
     * @param  array{event?: ?string, start_date?: ?string, end_date?: ?string, event_day?: int|string|null}  $input
     * @return array{filters: array{event: ?string, start_date: ?string, end_date: ?string, event_day: ?int}, kpis: array<string, int|float|string>, charts: array<string, array<int, array<string, int|float|string>>>, event_days: array<int, array{id: int, label: string}>}
     */
    public function data(array $input): array
    {
        $filters = [
            'event' => $input['event'] ?? null,
            'start_date' => $input['start_date'] ?? null,
            'end_date' => $input['end_date'] ?? null,
            'event_day' => empty($input['event_day']) ? null : (int) $input['event_day'],
        ];
        $eventQuery = Event::query()->when($filters['event'], fn (Builder $query, string $uuid): Builder => $query->where('uuid', $uuid));
        if ($filters['start_date'] || $filters['end_date'] || $filters['event_day']) {
            $eventQuery->whereHas('days', fn (Builder $query): Builder => $this->filterDays($query, $filters));
        }
        $events = (clone $eventQuery)->select(['id', 'uuid', 'title'])
            ->withCount(['registrations as active_registrations' => fn (Builder $query): Builder => $query->registered()])
            ->orderBy('title')->orderBy('id')->get()->keyBy('id');
        $includedDays = $this->filterDays(EventDay::query(), $filters)
            ->whereIn('event_id', (clone $eventQuery)->select('events.id'));
        $days = (clone $includedDays)->orderBy('date')->orderBy('event_id')->orderBy('day_number')
            ->get(['id', 'event_id', 'date', 'day_number', 'title']);

        $invitations = $this->attendance->invitations()->whereIn('event_days.id', (clone $includedDays)->select('id'));
        $expectedByDay = (clone $invitations)->select('event_days.id')->selectRaw('count(*) as invited')
            ->groupBy('event_days.id')->pluck('invited', 'id');
        $slots = $invitations
            ->join('attendances', fn (JoinClause $join): JoinClause => $join
                ->on('attendances.event_registration_id', '=', 'event_registrations.id')
                ->on('attendances.event_day_id', '=', 'event_days.id'))
            ->select([
                'attendances.event_registration_id', 'attendances.event_day_id',
                'event_registrations.registration_type', 'event_registrations.employee_id', 'event_registrations.external_participant_id',
            ])->distinct();
        $attendanceByDay = DB::query()->fromSub(clone $slots, 'attendance_slots')->select('event_day_id')
            ->selectRaw('count(*) as checked_in')
            ->selectRaw('sum(case when registration_type = ? then 1 else 0 end) as employee_checkins', [EventRegistration::TYPE_EMPLOYEE])
            ->selectRaw('sum(case when registration_type = ? then 1 else 0 end) as external_checkins', [EventRegistration::TYPE_EXTERNAL])
            ->groupBy('event_day_id')->get()->keyBy('event_day_id');
        $attendedRegistrations = DB::query()->fromSub(clone $slots, 'attendance_slots')->distinct()->count('event_registration_id');
        $participantsAttended = $attendedRegistrations;
        $participantMetric = 'participants';
        if ($filters['event'] === null) {
            $identities = DB::query()->fromSub(clone $slots, 'attendance_slots')->select('registration_type')
                ->selectRaw('case when registration_type = ? and employee_id is not null and external_participant_id is null then employee_id
                    when registration_type = ? and external_participant_id is not null and employee_id is null then external_participant_id
                    else null end as participant_id', [EventRegistration::TYPE_EMPLOYEE, EventRegistration::TYPE_EXTERNAL])->distinct();
            $identityCounts = DB::query()->fromSub($identities, 'participant_identities')
                ->selectRaw('count(*) as total, coalesce(sum(case when participant_id is null then 1 else 0 end), 0) as unresolved')->first();
            if ((int) $identityCounts->unresolved === 0) {
                $participantsAttended = (int) $identityCounts->total;
            } else {
                $participantMetric = 'registrations';
            }
        }

        $dayStats = $days->map(function (EventDay $day) use ($events, $attendanceByDay, $expectedByDay): array {
            $event = $events[$day->event_id];
            $attendance = $attendanceByDay->get($day->id);
            $checkedIn = (int) ($attendance?->checked_in ?? 0);
            $expected = (int) $expectedByDay->get($day->id, 0);

            return [
                'id' => $day->id, 'event_id' => $event->uuid,
                'label' => $event->title.' · Day '.$day->day_number.($day->title ? ' — '.$day->title : '').' · '.$day->date->format('d/m/Y'),
                'date' => $day->date->toDateString(), 'checked_in' => $checkedIn, 'expected' => $expected,
                'percentage' => $this->percentage($checkedIn, $expected),
                'employee_checkins' => (int) ($attendance?->employee_checkins ?? 0),
                'external_checkins' => (int) ($attendance?->external_checkins ?? 0),
            ];
        });
        $daysByEvent = $dayStats->groupBy('event_id');
        $eventStats = $events->map(function (Event $event) use ($daysByEvent): array {
            $eventDays = $daysByEvent->get($event->uuid, collect());
            $expected = (int) $eventDays->sum('expected');
            $checkedIn = (int) $eventDays->sum('checked_in');

            return [
                'event_id' => $event->uuid, 'label' => $event->title,
                'active_registrations' => (int) $event->active_registrations, 'included_days' => $eventDays->count(),
                'checked_in' => $checkedIn, 'expected' => $expected, 'percentage' => $this->percentage($checkedIn, $expected),
            ];
        })->values();
        $eligibleEvents = $eventStats->where('expected', '>', 0);
        $checkedIn = (int) $dayStats->sum('checked_in');
        $expected = (int) $eventStats->sum('expected');
        $trend = $dayStats->groupBy('date')->map(function (SupportCollection $dateDays, string $date): array {
            $checkedIn = (int) $dateDays->sum('checked_in');
            $expected = (int) $dateDays->sum('expected');

            return ['date' => $date, 'checked_in' => $checkedIn, 'expected' => $expected, 'percentage' => $this->percentage($checkedIn, $expected)];
        })->values()->all();
        $eventDays = $filters['event'] === null ? [] : EventDay::query()
            ->whereHas('event', fn (Builder $query): Builder => $query->where('uuid', $filters['event']))
            ->orderBy('date')->orderBy('day_number')->get(['id', 'day_number', 'date', 'title'])
            ->map(fn (EventDay $day): array => ['id' => $day->id, 'label' => 'Day '.$day->day_number.' · '.$day->date->format('d/m/Y').($day->title ? ' — '.$day->title : '')])->all();

        return [
            'filters' => $filters,
            'kpis' => [
                'total_events' => $eventStats->count(), 'total_registrations' => (int) $eventStats->sum('active_registrations'),
                'participants_attended' => $participantsAttended, 'participant_metric' => $participantMetric,
                'attended_registrations' => $attendedRegistrations, 'total_checkins' => $checkedIn,
                'expected_attendance' => $expected, 'attendance_rate' => $this->percentage($checkedIn, $expected),
                'average_event_rate' => $eligibleEvents->isEmpty() ? 0.0 : round($eligibleEvents->avg(fn (array $event): float => $event['checked_in'] / $event['expected'] * 100), 2),
                'eligible_events' => $eligibleEvents->count(),
            ],
            'charts' => [
                'attendance_trend' => $trend, 'by_event' => $eventStats->all(),
                'participant_types' => [
                    ['label' => 'Employee', 'count' => (int) $dayStats->sum('employee_checkins')],
                    ['label' => 'External', 'count' => (int) $dayStats->sum('external_checkins')],
                ],
                'by_day' => $dayStats->values()->all(),
            ],
            'event_days' => $eventDays,
        ];
    }

    /**
     * @param  array{start_date: ?string, end_date: ?string, event_day: ?int}  $filters
     */
    private function filterDays(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['start_date'], fn (Builder $query, string $date): Builder => $query->where('event_days.date', '>=', $date))
            ->when($filters['end_date'], fn (Builder $query, string $date): Builder => $query->where('event_days.date', '<=', $date))
            ->when($filters['event_day'], fn (Builder $query, int $id): Builder => $query->where('event_days.id', $id));
    }

    private function percentage(int $checkedIn, int $expected): float
    {
        return $expected === 0 ? 0.0 : min(100.0, round($checkedIn / $expected * 100, 2));
    }
}
