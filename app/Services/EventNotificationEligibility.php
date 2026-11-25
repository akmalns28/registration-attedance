<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;

class EventNotificationEligibility
{
    public const MISSING_EMAIL = 'Recipient email is missing or invalid.';

    public function email(?string $email): ?string
    {
        $email = trim((string) $email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function recipient(EventRegistration $registration): ?string
    {
        $participant = $registration->isEmployee() ? $registration->employee : $registration->externalParticipant;

        return $this->email($participant?->email);
    }

    public function registrationReason(EventRegistration $registration, string $type): ?string
    {
        if (! in_array($type, EventRegistrationNotification::types(), true)) {
            return 'Invalid notification type.';
        }

        if (! $registration->event) {
            return 'Event not found.';
        }

        $participant = $registration->isEmployee() ? $registration->employee : $registration->externalParticipant;

        if (! $participant) {
            return 'Participant not found.';
        }

        if ($type === EventRegistrationNotification::TYPE_CANCELLATION) {
            return $registration->isCancelled() ? null : 'Registration is no longer cancelled.';
        }

        if (! $registration->isRegistered() || $registration->event->status !== Event::STATUS_PUBLISHED) {
            return 'Registration or event is no longer active.';
        }

        return null;
    }

    public function reason(EventRegistrationNotification $notification): ?string
    {
        $registration = $notification->registration;

        if (! $registration) {
            return 'Registration not found.';
        }

        if ((int) $notification->event_id !== (int) $registration->event_id) {
            return 'Notification event does not match registration.';
        }

        if ($reason = $this->registrationReason($registration, $notification->notification_type)) {
            return $reason;
        }

        return $this->dayReason($registration, $notification->notification_type)
            ?? ($this->email($notification->recipient_email) === null ? self::MISSING_EMAIL : null);
    }

    public function dayReason(EventRegistration $registration, string $type): ?string
    {
        if (! in_array($type, [EventRegistrationNotification::TYPE_INVITATION, EventRegistrationNotification::TYPE_REMINDER], true)) {
            return null;
        }

        return $registration->loadMissing('activeInvitedDays')->activeInvitedDays->isEmpty()
            ? 'Peserta belum memiliki hari undangan aktif.'
            : null;
    }
}
