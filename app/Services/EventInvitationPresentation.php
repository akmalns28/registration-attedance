<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use Illuminate\Support\Collection;

class EventInvitationPresentation
{
    /** @return Collection<int, EventDay> */
    public function invitedDays(Event $event, EventRegistration $registration): Collection
    {
        return $registration->loadMissing('activeInvitedDays')->activeInvitedDays
            ->where('event_id', $event->id)->sortBy('date')->values();
    }

    /**
     * @param  Collection<int, EventDay>  $days
     * @return array{days: list<array{date: string, time: ?string, dress_code: ?string}>, dress_code: ?string}
     */
    public function attendance(Event $event, Collection $days): array
    {
        $rows = $days->map(fn (EventDay $day): array => [
            'date' => $day->date->locale('id')->translatedFormat('j F Y'),
            'time' => $day->attendanceTime(),
            'dress_code' => $day->effectiveDressCode($event),
        ]);
        $dressCodes = $rows->pluck('dress_code')->uniqueStrict();

        return [
            'days' => $rows->all(),
            'dress_code' => $dressCodes->count() === 1 ? $dressCodes->first() : null,
        ];
    }
}
