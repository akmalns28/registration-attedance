<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventDay\StoreEventDayRequest;
use App\Http\Requests\EventDay\UpdateEventDayRequest;
use App\Models\Event;
use App\Models\EventDay;
use App\Services\AttendanceScannerContext;
use App\Services\EventDayAttendanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EventDayController extends Controller
{
    public function show(Event $event, EventDay $day, AttendanceScannerContext $scannerContext, EventDayAttendanceService $attendance): View
    {
        $this->ensureBelongsToEvent($event, $day);
        $day->load('schedules');
        $user = auth()->user();
        $canScan = $user->can('attendance.scan') && $event->isPublished() && $day->is_active
            && $day->date->betweenIncluded($event->start_date, $event->end_date);
        $canViewAttendance = $user->can('attendance.view');
        $canViewInvitations = $canViewAttendance || $user->canAny(['registrations.view', 'events.update']);
        $invitees = $canViewInvitations ? $day->invitations()->where('event_registrations.event_id', $event->id)
            ->with(['employee', 'externalParticipant'])
            ->when($user->canAny(['registrations.view', 'events.update']), fn ($query) => $query->with('latestInvitation'))
            ->when($canViewAttendance, fn ($query) => $query->withExists([
                'attendances as checked_in' => fn (Builder $query): Builder => $query->where('event_day_id', $day->id),
            ]))
            ->orderBy('event_registrations.id')->get() : collect();

        return view('events.days.show', [
            'event' => $event,
            'day' => $day,
            'attendanceSummary' => $attendance->summary($event, $day),
            'attendees' => $canViewAttendance ? $attendance->attendees($event, $day) : [],
            'attendanceRefreshUrl' => $canViewAttendance ? route('events.days.attendance', [$event, $day], absolute: false) : null,
            'scannerData' => $canScan ? $scannerContext->viewData($event, $day) : null,
            'canViewInvitations' => $canViewInvitations,
            'invitees' => $invitees,
        ]);
    }

    public function attendance(Event $event, EventDay $day, EventDayAttendanceService $attendance): JsonResponse
    {
        $this->ensureBelongsToEvent($event, $day);

        return response()->json([
            'success' => true,
            'summary' => $attendance->summary($event, $day),
            'attendees' => $attendance->attendees($event, $day),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function create(Event $event): View
    {
        return view('events.days.create', [
            'event' => $event,
        ]);
    }

    public function store(StoreEventDayRequest $request, Event $event): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($event, $data): void {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $event->days()->create([
                ...$data,
                'day_number' => $this->dayNumber($event, $data['date']),
            ]);
        }, attempts: 3);

        return redirect()
            ->to(route('events.show', $event).'#event-days')
            ->with('success', 'Hari pelaksanaan berhasil ditambahkan.');
    }

    public function edit(Event $event, EventDay $day): View
    {
        $this->ensureBelongsToEvent($event, $day);

        return view('events.days.edit', [
            'event' => $event,
            'day' => $day,
        ]);
    }

    public function update(UpdateEventDayRequest $request, Event $event, EventDay $day): RedirectResponse
    {
        $this->ensureBelongsToEvent($event, $day);

        $data = $request->validated();

        DB::transaction(function () use ($event, $day, $data): void {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $lockedDay = $event->days()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $lockedDay->update([
                ...$data,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'day_number' => $this->dayNumber($event, $data['date']),
            ]);
        }, attempts: 3);

        return redirect()
            ->to(route('events.show', $event).'#event-days')
            ->with('success', 'Hari pelaksanaan berhasil diperbarui.');
    }

    public function destroy(Event $event, EventDay $day): RedirectResponse
    {
        Gate::authorize('events.update');

        $this->ensureBelongsToEvent($event, $day);

        $error = DB::transaction(function () use ($event, $day): ?string {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $lockedDay = EventDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            if ($lockedDay->schedules()->exists()) {
                return 'Event Day tidak dapat dihapus karena sudah memiliki rundown.';
            }
            if ($lockedDay->attendances()->exists()) {
                return 'Hari pelaksanaan tidak dapat dihapus karena memiliki riwayat kehadiran.';
            }
            if ($lockedDay->invitations()->exists()) {
                return 'Hari pelaksanaan tidak dapat dihapus karena memiliki riwayat undangan.';
            }
            $lockedDay->delete();

            return null;
        }, attempts: 3);

        if ($error !== null) {
            return redirect()->to(route('events.show', $event).'#event-days')->with('error', $error);
        }

        return redirect()
            ->to(route('events.show', $event).'#event-days')
            ->with('success', 'Hari pelaksanaan berhasil dihapus.');
    }

    private function ensureBelongsToEvent(Event $event, EventDay $day): void
    {
        abort_unless($day->event_id === $event->id, 404);
    }

    private function dayNumber(Event $event, string $value): int
    {
        $date = Carbon::parse($value);
        if (! $date->betweenIncluded($event->start_date, $event->end_date)) {
            throw ValidationException::withMessages(['date' => 'Tanggal pelaksanaan harus berada dalam rentang tanggal event.']);
        }

        return (int) $event->start_date->diffInDays($date) + 1;
    }
}
