<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventDay\StoreEventDayRequest;
use App\Http\Requests\EventDay\UpdateEventDayRequest;
use App\Models\Event;
use App\Models\EventDay;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class EventDayController extends Controller
{
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

        if ($day->schedules()->exists()) {
            return redirect()
                ->to(route('events.show', $event).'#event-days')
                ->with('error', 'Event Day tidak dapat dihapus karena sudah memiliki rundown.');
        }

        $day->delete();

        return redirect()
            ->to(route('events.show', $event).'#event-days')
            ->with('success', 'Hari pelaksanaan berhasil dihapus.');
    }

    private function ensureBelongsToEvent(Event $event, EventDay $day): void
    {
        abort_unless($day->event_id === $event->id, 404);
    }
}
