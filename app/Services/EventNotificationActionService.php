<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventNotificationActionService
{
    public function __construct(
        private readonly EventNotificationTransport $transport,
        private readonly EventNotificationEligibility $eligibility,
        private readonly EventNotificationQueue $queue,
        private readonly EventNotificationService $notifications,
        private readonly EventPublicationNotificationService $publication,
    ) {}

    public function retry(EventRegistrationNotification $notification): EventRegistrationNotification
    {
        $this->ensureTransport();

        return DB::transaction(function () use ($notification): EventRegistrationNotification {
            $notification = $this->lockNotification($notification);
            $this->queueExisting($notification);

            return $notification;
        }, attempts: 3);
    }

    /**
     * A source notification permits one deliberate resend. Repeated form
     * submissions return that same child notification without sending twice.
     */
    public function resend(EventRegistrationNotification $notification): EventRegistrationNotification
    {
        $this->ensureTransport();

        return DB::transaction(function () use ($notification): EventRegistrationNotification {
            $source = $this->lockNotification($notification);

            if (! in_array($source->status, [EventRegistrationNotification::STATUS_SENT, EventRegistrationNotification::STATUS_SKIPPED], true)) {
                throw ValidationException::withMessages(['notification' => 'Gunakan Coba lagi untuk notifikasi yang belum selesai.']);
            }

            if ($reason = $this->eligibility->registrationReason($source->registration, $source->notification_type)) {
                throw ValidationException::withMessages(['notification' => $reason]);
            }

            if ($this->eligibility->recipient($source->registration) === null) {
                throw ValidationException::withMessages(['notification' => 'Peserta belum memiliki alamat email yang valid.']);
            }

            $resent = $this->notifications->schedule(
                $source->registration,
                $source->notification_type,
                'notification:'.$source->id.':resend:v1',
            );

            if ($resent->wasRecentlyCreated) {
                $metadata = $resent->metadata ?? [];
                $metadata['resend_of'] = $source->id;
                $resent->update(['metadata' => $metadata]);
            }

            return $resent;
        }, attempts: 3);
    }

    /**
     * @return array{created: int, queued: int, skipped: int}
     */
    public function invitations(Event $event): array
    {
        $this->ensureTransport();

        return DB::transaction(function () use ($event): array {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $event->isPublished()) {
                throw ValidationException::withMessages(['notification' => 'Publikasikan event sebelum mengirim undangan.']);
            }

            $counts = ['created' => 0, 'queued' => 0, 'skipped' => 0];
            $event->registrations()->registered()->chunkById(100, function ($registrations) use (&$counts): void {
                foreach ($registrations as $registration) {
                    $notification = $this->publication->scheduleForRegistration($registration);

                    if ($notification->wasRecentlyCreated) {
                        $counts['created']++;

                        if ($notification->status === EventRegistrationNotification::STATUS_QUEUED) {
                            $counts['queued']++;
                        } else {
                            $counts['skipped']++;
                        }

                        continue;
                    }

                    if (in_array($notification->status, [EventRegistrationNotification::STATUS_PENDING, EventRegistrationNotification::STATUS_QUEUED, EventRegistrationNotification::STATUS_FAILED], true)
                        && ! $notification->scheduled_for?->isFuture()
                        && ($notification->status !== EventRegistrationNotification::STATUS_QUEUED || ($notification->queued_at ?? $notification->updated_at)->lte(now()->subSeconds(max(1, (int) config('event_notifications.stale_after', 300)))))
                        && $notification->attempts < max(1, (int) config('event_notifications.tries', 3))
                        && $this->eligibility->reason($notification->loadMissing(['registration.event', 'registration.employee', 'registration.externalParticipant'])) === null
                        && $this->queue->hasDatabaseJob($notification) === false) {
                        if ($notification->status === EventRegistrationNotification::STATUS_FAILED
                            && ($notification->failed_at ?? $notification->updated_at)->isAfter(now()->subSeconds($this->queue->retryDelay($notification->attempts)))) {
                            $counts['skipped']++;

                            continue;
                        }

                        $this->queue->enqueue($notification);
                        $counts['queued']++;
                    } else {
                        $counts['skipped']++;
                    }
                }
            });

            return $counts;
        }, attempts: 3);
    }

    private function queueExisting(EventRegistrationNotification $notification): void
    {
        if (in_array($notification->status, [EventRegistrationNotification::STATUS_SENT, EventRegistrationNotification::STATUS_SKIPPED], true)) {
            throw ValidationException::withMessages(['notification' => 'Gunakan Kirim ulang untuk membuat pengiriman baru.']);
        }

        if ($notification->status === EventRegistrationNotification::STATUS_PROCESSING
            && ($notification->last_attempt_at ?? $notification->updated_at)->isAfter(now()->subSeconds($this->queue->leaseSeconds()))) {
            throw ValidationException::withMessages(['notification' => 'Notifikasi sedang diproses. Tunggu hingga proses selesai.']);
        }

        if ($notification->attempts >= max(1, (int) config('event_notifications.tries', 3))) {
            throw ValidationException::withMessages(['notification' => 'Batas percobaan pengiriman sudah tercapai. Periksa penyebab kegagalan terlebih dahulu.']);
        }

        if ($notification->scheduled_for?->isFuture()) {
            throw ValidationException::withMessages(['notification' => 'Jadwal pengiriman notifikasi belum tiba.']);
        }

        if ($notification->status === EventRegistrationNotification::STATUS_FAILED
            && ($notification->failed_at ?? $notification->updated_at)->isAfter(now()->subSeconds($this->queue->retryDelay($notification->attempts)))) {
            throw ValidationException::withMessages(['notification' => 'Tunggu jeda percobaan pengiriman sebelum mencoba lagi.']);
        }

        if ($reason = $this->eligibility->reason($notification)) {
            throw ValidationException::withMessages(['notification' => $reason]);
        }

        $activeJob = $this->queue->hasDatabaseJob($notification);

        if ($activeJob === null) {
            throw ValidationException::withMessages(['notification' => 'Status antrean tidak dapat diverifikasi. Periksa worker sebelum mencoba lagi.']);
        }

        if ($notification->status === EventRegistrationNotification::STATUS_QUEUED
            && ($notification->queued_at ?? $notification->updated_at)->isAfter(now()->subSeconds(max(1, (int) config('event_notifications.stale_after', 300))))) {
            return;
        }

        if (! $activeJob) {
            $this->queue->enqueue($notification);
        }
    }

    private function lockNotification(EventRegistrationNotification $notification): EventRegistrationNotification
    {
        Event::query()->whereKey($notification->event_id)->lockForUpdate()->firstOrFail();
        EventRegistration::query()->whereKey($notification->event_registration_id)->lockForUpdate()->firstOrFail();

        return EventRegistrationNotification::query()
            ->with(['registration.event', 'registration.employee', 'registration.externalParticipant'])
            ->whereKey($notification->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureTransport(): void
    {
        if ($problem = $this->transport->problem()) {
            throw ValidationException::withMessages(['notification' => $problem]);
        }
    }
}
