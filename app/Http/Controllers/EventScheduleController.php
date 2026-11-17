<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventSchedule\StoreEventScheduleRequest;
use App\Http\Requests\EventSchedule\UpdateEventScheduleRequest;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventSchedule;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class EventScheduleController extends Controller
{
    /**
     * Show create rundown form.
     */
    public function create(Event $event, EventDay $day): View
    {
        $this->ensureBelongsToEvent($event, $day);

        return view('events.schedules.create', [
            'event' => $event,
            'day' => $day,
        ]);
    }

    /**
     * Store new rundown.
     */
    public function store(StoreEventScheduleRequest $request, Event $event, EventDay $day): RedirectResponse
    {
        $this->ensureBelongsToEvent($event, $day);

        $day->schedules()->create($request->validated());

        return redirect()
            ->to(route('events.days.show', [$event, $day]).'#day-rundown')
            ->with('success', 'Rundown berhasil ditambahkan.');
    }

    /**
     * Show edit rundown form.
     */
    public function edit(Event $event, EventDay $day, EventSchedule $schedule): View
    {
        $this->ensureBelongsToEvent($event, $day);

        $this->ensureBelongsToDay($day, $schedule);

        return view('events.schedules.edit', [
            'event' => $event,
            'day' => $day,
            'schedule' => $schedule,
        ]);
    }

    /**
     * Update rundown.
     */
    public function update(UpdateEventScheduleRequest $request, Event $event, EventDay $day, EventSchedule $schedule): RedirectResponse
    {
        $this->ensureBelongsToEvent($event, $day);

        $this->ensureBelongsToDay($day, $schedule);

        $schedule->update($request->validated());

        return redirect()
            ->to(route('events.days.show', [$event, $day]).'#day-rundown')
            ->with('success', 'Rundown berhasil diperbarui.');
    }

    /**
     * Delete rundown.
     */
    public function destroy(Event $event, EventDay $day, EventSchedule $schedule): RedirectResponse
    {
        Gate::authorize('events.update');

        $this->ensureBelongsToEvent($event, $day);

        $this->ensureBelongsToDay($day, $schedule);

        $schedule->delete();

        return redirect()
            ->to(route('events.days.show', [$event, $day]).'#day-rundown')
            ->with('success', 'Rundown berhasil dihapus.');
    }

    private function ensureBelongsToEvent(Event $event, EventDay $day): void
    {
        abort_unless($day->event_id === $event->id, 404);
    }

    private function ensureBelongsToDay(EventDay $day, EventSchedule $schedule): void
    {
        abort_unless($schedule->event_day_id === $day->id, 404);
    }
}
