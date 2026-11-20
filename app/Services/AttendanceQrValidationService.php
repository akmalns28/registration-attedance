<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceQrValidationService
{
    /**
     * Validate and resolve a registration QR payload.
     *
     * Format: RA1:{registration_uuid}
     */
    public function resolve(string $payload): EventRegistration
    {
        $payload = trim($payload);

        if (! preg_match('/^RA1:([0-9a-fA-F-]{36})$/D', $payload, $matches)) {
            throw ValidationException::withMessages([
                'qr_code' => 'Format QR Code tidak valid.',
            ]);
        }

        $uuid = $matches[1];

        if (! Str::isUuid($uuid)) {
            throw ValidationException::withMessages([
                'qr_code' => 'UUID registrasi tidak valid.',
            ]);
        }

        $registration = EventRegistration::query()
            ->with(['event', 'employee', 'externalParticipant'])
            ->where('uuid', strtolower($uuid))
            ->first();

        if (! $registration) {
            throw ValidationException::withMessages([
                'qr_code' => 'Registrasi tidak ditemukan.',
            ]);
        }

        return $registration;
    }

    public function validate(string $payload, Event $event, EventDay $day): EventRegistration
    {
        $this->validateContext($event, $day);

        return $this->validateRegistration($this->resolve($payload), $event, $day);
    }

    public function validateContext(Event $event, EventDay $day): void
    {
        if (! $event->isPublished()) {
            throw ValidationException::withMessages([
                'event_id' => 'Validasi QR hanya tersedia untuk event Published.',
            ]);
        }

        if ((int) $day->event_id !== (int) $event->id || ! $day->is_active
            || $day->date->isBefore($event->start_date) || $day->date->isAfter($event->end_date)) {
            throw ValidationException::withMessages([
                'event_day_id' => 'Hari pelaksanaan tidak valid atau tidak aktif untuk event yang dipilih.',
            ]);
        }
    }

    public function validateRegistration(EventRegistration $registration, Event $event, EventDay $day): EventRegistration
    {
        $this->validateContext($event, $day);

        if ((int) $registration->event_id !== (int) $event->id) {
            throw ValidationException::withMessages([
                'qr_code' => 'QR Code tidak sesuai dengan event yang dipilih.',
            ]);
        }

        if (! $registration->isRegistered()) {
            throw ValidationException::withMessages([
                'qr_code' => 'Registrasi peserta telah dibatalkan atau tidak aktif.',
            ]);
        }

        $participantIsEligible = match ($registration->registration_type) {
            EventRegistration::TYPE_EMPLOYEE => $registration->employee?->is_active
                && $registration->external_participant_id === null,
            EventRegistration::TYPE_EXTERNAL => $registration->externalParticipant?->is_active
                && $registration->employee_id === null,
            default => false,
        };

        if (! $participantIsEligible) {
            throw ValidationException::withMessages([
                'qr_code' => 'Peserta tidak ditemukan atau tidak aktif.',
            ]);
        }

        if (! $registration->invitedDays()->whereKey($day->id)->exists()) {
            throw ValidationException::withMessages([
                'event_day_id' => 'Peserta tidak memiliki undangan aktif untuk hari pelaksanaan ini.',
            ]);
        }

        return $registration;
    }
}
