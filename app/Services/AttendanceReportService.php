<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

class AttendanceReportService
{
    /**
     * Every row represents an active registration and one of its Event's days.
     * The first attendance ID is canonical if historical duplicate rows exist.
     * Summary counts include every filtered row, including the text search and attendance status.
     *
     * @param  array{event_day?: int|string|null, participant_type?: ?string, attendance_status?: ?string, department_id?: int|string|null, draw?: int|string, start?: int|string, length?: int|string, search?: array{value?: ?string, regex?: mixed}, order?: array<int, array{column: int|string, dir: string}>}  $filters
     * @return array{draw: int, recordsTotal: int, recordsFiltered: int, data: array<int, array<string, int|string>>, summary: array{expected: int, present: int, not_present: int, percentage: float}}
     */
    public function data(Event $event, array $filters): array
    {
        $recordsTotal = DB::table('event_registrations')
            ->join('event_days', 'event_days.event_id', '=', 'event_registrations.event_id')
            ->where('event_registrations.event_id', $event->id)
            ->where('event_registrations.status', EventRegistration::STATUS_REGISTERED)->count();
        $query = $this->query($event, $filters);
        $summary = $this->summarize($query);
        $start = (int) ($filters['start'] ?? 0);
        $rows = $query->offset($start)->limit((int) ($filters['length'] ?? 10))->get();

        return [
            'draw' => (int) ($filters['draw'] ?? 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $summary['expected'],
            'data' => $rows->map(fn (stdClass $row, int $index): array => $this->mapRow($row, $start + $index + 1))->all(),
            'summary' => $summary,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function query(Event $event, array $filters): Builder
    {
        $query = $this->rows($event)
            ->when($filters['event_day'] ?? null, fn (Builder $query, int|string $id): Builder => $query->where('event_days.id', $id))
            ->when($filters['participant_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('event_registrations.registration_type', $type))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $id): Builder => $query
                ->where('event_registrations.registration_type', EventRegistration::TYPE_EMPLOYEE)->where('employees.department_id', $id));
        if (($filters['attendance_status'] ?? null) === 'present') {
            $query->whereNotNull('report_attendance.id');
        } elseif (($filters['attendance_status'] ?? null) === 'not_present') {
            $query->whereNull('report_attendance.id');
        }
        $search = trim($filters['search']['value'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn (Builder $query): Builder => $query
                ->whereRaw("coalesce(employees.name, external_participants.name, '-') like ? escape '!'", [$pattern])
                ->orWhereRaw("event_registrations.registration_number like ? escape '!'", [$pattern]));
        }
        $columns = [
            1 => 'event_registrations.registration_number', 2 => 'participant_name', 3 => 'event_registrations.registration_type',
            4 => 'departments.name', 5 => 'event_days.day_number', 6 => 'event_days.date',
            7 => 'attendance_status', 8 => 'report_attendance.checked_in_at', 9 => 'operators.name',
        ];
        $sort = $filters['order'][0] ?? ['column' => 6, 'dir' => 'asc'];

        return $query->select([
            'event_registrations.registration_number', 'event_registrations.registration_type',
            'departments.name as department_name', 'event_days.id as event_day_id', 'event_days.day_number',
            'event_days.title as day_title', 'event_days.date as event_day_date',
            'report_attendance.checked_in_at', 'operators.name as operator_name',
        ])->selectRaw("coalesce(employees.name, external_participants.name, '-') as participant_name")
            ->selectRaw("case when report_attendance.id is null then 'not_present' else 'present' end as attendance_status")
            ->orderBy($columns[(int) $sort['column']], $sort['dir'])
            ->orderBy('event_registrations.id')->orderBy('event_days.id');
    }

    /**
     * Pagination is intentionally excluded from exports.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, array<string, int|string>>
     */
    public function exportRows(Event $event, array $filters): LazyCollection
    {
        return $this->query($event, $filters)->lazy(500)
            ->map(fn (stdClass $row, int $index): array => $this->mapRow($row, $index + 1));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{expected: int, present: int, not_present: int, percentage: float}
     */
    public function summary(Event $event, array $filters): array
    {
        return $this->summarize($this->query($event, $filters));
    }

    /** @return array{expected: int, present: int, not_present: int, percentage: float} */
    private function summarize(Builder $query): array
    {
        $totals = (clone $query)->reorder()->select(DB::raw('count(*) as expected, coalesce(sum(case when report_attendance.id is not null then 1 else 0 end), 0) as present'))->first();
        $expected = (int) $totals->expected;
        $present = (int) $totals->present;

        return [
            'expected' => $expected, 'present' => $present, 'not_present' => $expected - $present,
            'percentage' => $expected === 0 ? 0.0 : round($present / $expected * 100, 2),
        ];
    }

    /** @return array<string, int|string> */
    private function mapRow(stdClass $row, int $number): array
    {
        return [
            'row_number' => $number,
            'registration_number' => $row->registration_number,
            'participant_name' => $row->participant_name,
            'participant_type' => $row->registration_type,
            'department_name' => $row->department_name ?? '-',
            'event_day' => 'Day '.$row->day_number.($row->day_title ? ' — '.$row->day_title : ''),
            'event_day_date' => $row->event_day_date,
            'attendance_status' => $row->attendance_status,
            'checkin_time' => $row->checked_in_at === null ? '-' : Carbon::parse($row->checked_in_at, config('app.timezone'))->format('H:i:s'),
            'operator_name' => $row->operator_name ?? '-',
        ];
    }

    private function rows(Event $event): Builder
    {
        $attendanceIds = Attendance::query()->select(['event_registration_id', 'event_day_id'])
            ->selectRaw('min(attendances.id) as attendance_id')
            ->whereIn('event_registration_id', $event->registrations()->registered()->select('id'))
            ->whereIn('event_day_id', $event->days()->select('id')->reorder())
            ->groupBy('event_registration_id', 'event_day_id');

        return DB::table('event_registrations')
            ->join('event_days', 'event_days.event_id', '=', 'event_registrations.event_id')
            ->leftJoin('employees', fn (JoinClause $join): JoinClause => $join->on('employees.id', '=', 'event_registrations.employee_id')
                ->where('event_registrations.registration_type', EventRegistration::TYPE_EMPLOYEE))
            ->leftJoin('external_participants', fn (JoinClause $join): JoinClause => $join->on('external_participants.id', '=', 'event_registrations.external_participant_id')
                ->where('event_registrations.registration_type', EventRegistration::TYPE_EXTERNAL))
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->leftJoinSub($attendanceIds, 'attendance_ids', fn (JoinClause $join): JoinClause => $join
                ->on('attendance_ids.event_registration_id', '=', 'event_registrations.id')
                ->on('attendance_ids.event_day_id', '=', 'event_days.id'))
            ->leftJoin('attendances as report_attendance', 'report_attendance.id', '=', 'attendance_ids.attendance_id')
            ->leftJoin('users as operators', 'operators.id', '=', 'report_attendance.checked_in_by')
            ->where('event_registrations.event_id', $event->id)
            ->where('event_registrations.status', EventRegistration::STATUS_REGISTERED);
    }
}
