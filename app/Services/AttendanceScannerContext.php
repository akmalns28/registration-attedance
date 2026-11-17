<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

class AttendanceScannerContext
{
    /**
     * @return array{
     *     events: array<int, array{id: int, title: string, days: array<int, array{id: int, label: string}>}>,
     *     fixedEvent: ?Event, fixedDay: ?EventDay, validationUrl: string, checkinUrl: string
     * }
     */
    public function viewData(?Event $fixedEvent = null, ?EventDay $fixedDay = null): array
    {
        if ($fixedDay !== null) {
            abort_unless($fixedEvent !== null && (int) $fixedDay->event_id === (int) $fixedEvent->id, 404);
            abort_unless($fixedEvent->isPublished() && $fixedDay->is_active
                && $fixedDay->date->betweenIncluded($fixedEvent->start_date, $fixedEvent->end_date), 403);
        }

        $eligibleDays = fn (Builder $query): Builder => $query->active()
            ->whereColumn('date', '>=', 'events.start_date')
            ->whereColumn('date', '<=', 'events.end_date');

        $events = Event::query()->published()
            ->when($fixedEvent !== null,
                fn (Builder $query): Builder => $query->whereKey($fixedEvent->getKey()),
                fn (Builder $query): Builder => $query->whereHas('days', $eligibleDays))
            ->with(['days' => fn (HasMany $days): HasMany => $days->active()])
            ->orderBy('start_date')->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'days' => $event->days
                    ->filter(fn (EventDay $day): bool => $day->date->betweenIncluded($event->start_date, $event->end_date)
                        && ($fixedDay === null || $day->is($fixedDay)))
                    ->map(fn (EventDay $day): array => [
                        'id' => $day->id,
                        'label' => 'Day '.$day->day_number.' - '.$day->date->format('d/m/Y').($day->title ? ' - '.$day->title : ''),
                    ])->values()->all(),
            ])->all();

        $parameters = $fixedEvent === null ? [] : ['event' => $fixedEvent->uuid];
        if ($fixedDay !== null) {
            $parameters['day'] = $fixedDay->id;
        }

        return [
            'events' => $events,
            'fixedEvent' => $fixedEvent,
            'fixedDay' => $fixedDay,
            'validationUrl' => $fixedEvent === null ? route('attendance.scanner.validate')
                : URL::signedRoute('attendance.scanner.validate', $parameters, absolute: false),
            'checkinUrl' => $fixedEvent === null ? route('attendance.check-in')
                : URL::signedRoute('attendance.check-in', $parameters, absolute: false),
        ];
    }
}
