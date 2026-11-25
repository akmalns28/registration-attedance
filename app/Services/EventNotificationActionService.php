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

            if ($reason = $this->eligibility->registrationReason($source->registration, $source->notification_type)
                ?? $this->eligibility->dayReason($source->registration, $source->notification_type)) {
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

    public function sendInvitation(Event $event, EventRegistration $registration): EventRegistrationNotification
    {
        $this->ensureTransport();

        return DB::transaction(function () use ($event, $registration): EventRegistrationNotification {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $registration = $event->registrations()->whereKey($registration->id)->lockForUpdate()->firstOrFail();
            $type = EventRegistrationNotification::TYPE_INVITATION;

            if ($reason = $this->eligibility->registrationReason($registration, $type) ?? $this->eligibility->dayReason($registration, $type)) {
                throw ValidationException::withMessages(['notification' => $reason]);
            }
            if ($this->eligibility->recipient($registration) === null) {
                throw ValidationException::withMessages(['notification' => 'Peserta belum memiliki alamat email yang valid.']);
            }

            return $registration->latestInvitation()->first() ?? $this->publication->scheduleForRegistration($registration);
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $registrationIds  Registration UUIDs.
     * @return array{queued: int, skipped: int, no_email: int, pending: int, duplicate: int}
     */
    public function resendInvitations(Event $event, array $registrationIds, string $requestId): array
    {
        if (! $event->isPublished()) {
            throw ValidationException::withMessages(['notification' => 'Publikasikan event sebelum mengirim ulang undangan.']);
        }

        $connection = config('event_notifications.connection') ?: config('queue.default');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw ValidationException::withMessages(['notification' => 'Antrean email belum dikonfigurasi untuk pengiriman oleh worker.']);
        }

        $registrationIds = array_unique(array_map(strtolower(...), $registrationIds));
        $chunks = array_chunk($registrationIds, max(1, (int) config('event_notifications.batch_size', 100)));

        foreach ($chunks as $chunk) {
            if ($event->registrations()->whereIn('uuid', $chunk)->count() !== count($chunk)) {
                throw ValidationException::withMessages(['registration_ids' => 'Sebagian registrasi tidak ditemukan pada event ini.']);
            }
        }

        $this->ensureTransport();
        $counts = ['queued' => 0, 'skipped' => 0, 'no_email' => 0, 'pending' => 0, 'duplicate' => 0];

        foreach ($chunks as $chunk) {
            $batch = DB::transaction(function () use ($event, $chunk, $requestId): array {
                $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $registrations = $lockedEvent->registrations()->whereIn('uuid', $chunk)
                    ->with(['employee', 'externalParticipant'])->orderBy('id')->lockForUpdate()->get();
                $batch = ['dispatches' => [], 'skipped' => count($chunk) - $registrations->count(), 'no_email' => 0, 'duplicate' => 0];
                $type = EventRegistrationNotification::TYPE_INVITATION;

                foreach ($registrations as $registration) {
                    $registration->setRelation('event', $lockedEvent);
                    if ($this->eligibility->registrationReason($registration, $type) !== null) {
                        $batch['skipped']++;

                        continue;
                    }

                    $notification = $this->notifications->schedule(
                        $registration,
                        $type,
                        "event:{$lockedEvent->id}:bulk-resend:{$requestId}:registration:{$registration->id}",
                        metadata: ['bulk_resend' => true],
                    );

                    if (! $notification->wasRecentlyCreated) {
                        $batch['duplicate']++;
                    } elseif ($notification->isSkipped()) {
                        $batch['skipped']++;
                        $batch['no_email'] += (int) ($notification->last_error === EventNotificationEligibility::MISSING_EMAIL);
                    } else {
                        $batch['dispatches'][] = $notification->id;
                    }
                }

                return $batch;
            }, attempts: 3);

            // Read after commit so failed queue pushes can be reported as pending recovery.
            $queued = EventRegistrationNotification::query()->whereKey($batch['dispatches'])->whereNotNull('queued_at')->count();
            $counts['queued'] += $queued;
            $counts['pending'] += count($batch['dispatches']) - $queued;
            $counts['skipped'] += $batch['skipped'];
            $counts['no_email'] += $batch['no_email'];
            $counts['duplicate'] += $batch['duplicate'];
        }

        return $counts;
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
