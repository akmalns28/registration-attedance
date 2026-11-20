<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\AnalyticService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, AnalyticService $analytics): View
    {
        $events = $analytics->events();
        $uuid = $request->query('event') ?? '';
        abort_unless(is_string($uuid), 404, 'Event tidak ditemukan.');
        $selectedEvent = $events->firstWhere('uuid', '===', $uuid);
        abort_unless($uuid === '' || $selectedEvent !== null, 404, 'Event tidak ditemukan.');

        $eventQuery = Event::query()->when($selectedEvent, fn (Builder $query, Event $event): Builder => $query->whereKey($event->id));
        $registrations = EventRegistration::query()->registered()
            ->when($selectedEvent, fn (Builder $query, Event $event): Builder => $query->whereBelongsTo($event))
            ->select('registration_type')
            ->selectRaw('count(*) as total, count(distinct employee_id) as employees, count(distinct external_participant_id) as externals')
            ->groupBy('registration_type')->get()->keyBy('registration_type');
        $employees = $registrations->get(EventRegistration::TYPE_EMPLOYEE);
        $externals = $registrations->get(EventRegistration::TYPE_EXTERNAL);
        $attendance = $analytics->data(['event' => $selectedEvent?->uuid]);
        $byEvent = collect($attendance['charts']['by_event'])->keyBy('event_id');
        $employeeRegistrations = (int) ($employees?->total ?? 0);
        $externalRegistrations = (int) ($externals?->total ?? 0);

        return view('dashboard.index', [
            'user' => $request->user(),
            'events' => $events,
            'selectedEvent' => $selectedEvent,
            'stats' => [
                'total_events' => $attendance['kpis']['total_events'],
                'active_events' => (clone $eventQuery)->published()->count(),
                'total_participants' => (int) ($employees?->employees ?? 0) + (int) ($externals?->externals ?? 0),
                'total_registered' => $attendance['kpis']['total_registrations'],
                'day_invitations' => $attendance['kpis']['expected_attendance'],
                'total_attendance' => $attendance['kpis']['total_checkins'],
                'attendance_percentage' => $attendance['kpis']['attendance_rate'],
                'employee_registrations' => $employeeRegistrations,
                'external_registrations' => $externalRegistrations,
            ],
            'recentEvents' => $eventQuery->latest()->latest('id')->limit(5)->get(),
            'attendanceByEvent' => $byEvent,
            'charts' => [
                'registrations' => $byEvent->map(fn (array $event): array => [
                    'label' => $event['label'], 'count' => $event['active_registrations'],
                ])->values()->all(),
                'attendance' => array_map(fn (array $day): array => [
                    'label' => $day['label'], 'present' => $day['checked_in'], 'absent' => max(0, $day['expected'] - $day['checked_in']),
                ], $attendance['charts']['by_day']),
                'participants' => [$employeeRegistrations, $externalRegistrations],
            ],
        ]);
    }
}
