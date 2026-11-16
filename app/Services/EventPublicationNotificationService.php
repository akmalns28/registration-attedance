<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EventPublicationNotificationService
{
    public function __construct(private readonly EventNotificationService $notifications) {}

    public function scheduleForPublishedEvent(Event $event): int
    {
        $schedule = function () use ($event): int {
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($event->status !== Event::STATUS_PUBLISHED) {
                throw ValidationException::withMessages(['event' => 'Event harus berstatus Published.']);
            }

            $scheduled = 0;

            EventRegistration::query()->where('event_id', $event->id)->registered()
                ->chunkById(100, function ($registrations) use (&$scheduled): void {
                    foreach ($registrations as $registration) {
                        if ($this->scheduleForRegistration($registration)->wasRecentlyCreated) {
                            $scheduled++;
                        }
                    }
                });

            return $scheduled;
        };

        return DB::transactionLevel() > 0 ? $schedule() : DB::transaction($schedule, attempts: 3);
    }

    public function scheduleForRegistration(EventRegistration $registration): EventRegistrationNotification
    {
        $dedupeKey = sprintf('event:%d:registration:%d:invitation:v1', $registration->event_id, $registration->id);

        return $this->notifications->schedule($registration, EventRegistrationNotification::TYPE_INVITATION, $dedupeKey);
    }

    /**
     * Real transitions are identified by the caller while holding the Event lock.
     * The previous notification's ID survives gaps in history without count-based
     * key reuse. Repeated scheduling outside a transition returns the latest record.
     * Deleting all evidence of a delivery necessarily removes its deduplication.
     */
    public function scheduleStatusChange(EventRegistration $registration, string $type, bool $isNewTransition = false): EventRegistrationNotification
    {
        if (! in_array($type, [EventRegistrationNotification::TYPE_CANCELLATION, EventRegistrationNotification::TYPE_REACTIVATION], true)) {
            throw new InvalidArgumentException('Invalid registration status notification type.');
        }

        $schedule = function () use ($registration, $type, $isNewTransition): EventRegistrationNotification {
            Event::query()->whereKey($registration->event_id)->lockForUpdate()->firstOrFail();
            $registration = EventRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();

            $latest = $registration->notifications()
                ->whereIn('notification_type', [
                    EventRegistrationNotification::TYPE_CANCELLATION,
                    EventRegistrationNotification::TYPE_REACTIVATION,
                ])->orderByDesc('id')->first();

            if (! $isNewTransition && $latest?->notification_type === $type) {
                return $this->notifications->schedule($registration, $type, $latest->dedupe_key);
            }

            $dedupeKey = sprintf('registration:%d:%s:after:%d', $registration->id, $type, $latest?->id ?? 0);

            return $this->notifications->schedule($registration, $type, $dedupeKey);
        };

        return DB::transactionLevel() > 0 ? $schedule() : DB::transaction($schedule, attempts: 3);
    }
}
