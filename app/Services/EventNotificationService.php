<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EventNotificationService
{
    public function __construct(
        private readonly EventNotificationEligibility $eligibility,
        private readonly EventNotificationQueue $queue,
    ) {}

    /** @param array<string, mixed>|null $metadata */
    public function schedule(EventRegistration $registration, string $type, string $dedupeKey, ?array $metadata = null): EventRegistrationNotification
    {
        if (! in_array($type, EventRegistrationNotification::types(), true)) {
            throw new InvalidArgumentException('Jenis notifikasi tidak valid.');
        }

        if (trim($dedupeKey) === '' || strlen($dedupeKey) > 191) {
            throw new InvalidArgumentException('Notification dedupe key wajib diisi dan maksimal 191 karakter.');
        }

        $schedule = function () use ($registration, $type, $dedupeKey, $metadata): EventRegistrationNotification {
            $event = Event::query()->whereKey($registration->event_id)->lockForUpdate()->firstOrFail();
            $registration = EventRegistration::query()->whereKey($registration->getKey())
                ->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
            $registration->setRelation('event', $event);
            $registration->load(['employee', 'externalParticipant']);

            if ($this->eligibility->registrationReason($registration, $type) !== null) {
                throw ValidationException::withMessages([
                    'notification' => $type === EventRegistrationNotification::TYPE_INVITATION
                        ? 'Undangan hanya dapat dijadwalkan untuk peserta aktif pada event Published.'
                        : 'Registrasi tidak memenuhi syarat notifikasi.',
                ]);
            }

            $email = $this->eligibility->recipient($registration);
            $reason = $email === null ? EventNotificationEligibility::MISSING_EMAIL : $this->eligibility->dayReason($registration, $type);
            $notification = EventRegistrationNotification::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'event_id' => $registration->event_id,
                    'event_registration_id' => $registration->id,
                    'notification_type' => $type,
                    'recipient_email' => $email,
                    'status' => $reason === null ? EventRegistrationNotification::STATUS_PENDING : EventRegistrationNotification::STATUS_SKIPPED,
                    'attempts' => 0,
                    'scheduled_for' => now(),
                    'last_error' => $reason,
                    'metadata' => $metadata,
                ],
            );

            if ((int) $notification->event_id !== (int) $registration->event_id
                || (int) $notification->event_registration_id !== (int) $registration->id
                || $notification->notification_type !== $type) {
                throw new InvalidArgumentException('Dedupe key sudah digunakan oleh notifikasi lain.');
            }

            if ($notification->wasRecentlyCreated && $reason === null) {
                $this->queue->enqueue($notification);
            }

            return $notification;
        };

        return DB::transactionLevel() > 0 ? $schedule() : DB::transaction($schedule, attempts: 3);
    }
}
