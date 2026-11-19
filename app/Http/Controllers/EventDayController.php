<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventDay\StoreEventDayRequest;
use App\Http\Requests\EventDay\UpdateEventDayRequest;
use App\Models\Event;
use App\Models\EventDay;
use App\Services\AttendanceScannerContext;
use App\Services\EventDayAttendanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EventDayController extends Controller
{
    public function show(Event $event, EventDay $day, AttendanceScannerContext $scannerContext, EventDayAttendanceService $attendance): View
    {
        $this->ensureBelongsToEvent($event, $day);
        $day->load('schedules');
        $canScan = auth()->user()->can('attendance.scan') && $event->isPublished() && $day->is_active
            && $day->date->betweenIncluded($event->start_date, $event->end_date);

        return view('events.days.show', [
            'event' => $event,
            'day' => $day,
            'attendanceSummary' => $attendance->summary($event, $day),
            'attendees' => auth()->user()->can('attendance.view') ? $attendance->attendees($event, $day) : [],
            'attendanceRefreshUrl' => auth()->user()->can('attendance.view') ? route('events.days.attendance', [$event, $day], absolute: false) : null,
            'scannerData' => $canScan ? $scannerContext->viewData($event, $day) : null,
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

        $date = Carbon::parse($data['date']);

        $dayNumber = (int) $event->start_date->diffInDays($date) + 1;

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => $dayNumber,
            'date' => $data['date'],
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'],
        ]);

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

        $date = Carbon::parse($data['date']);

        $dayNumber = (int) $event->start_date->diffInDays($date) + 1;

        $day->update([
            'day_number' => $dayNumber,
            'date' => $data['date'],
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'],
        ]);

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
}
