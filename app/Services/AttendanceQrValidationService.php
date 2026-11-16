<?php

namespace App\Services;

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

        if (!preg_match(
            '/^RA1:([0-9a-fA-F-]{36})$/',
            $payload,
            $matches
        )) {
            throw ValidationException::withMessages([
                'qr_code' => 'Format QR Code tidak valid.',
            ]);
        }

        $uuid = $matches[1];

        if (!Str::isUuid($uuid)) {
            throw ValidationException::withMessages([
                'qr_code' => 'UUID registrasi tidak valid.',
            ]);
        }

        $registration = EventRegistration::query()
            ->with([
                'event',
                'employee',
                'externalParticipant',
            ])
            ->where('uuid', $uuid)
            ->first();

        if (!$registration) {
            throw ValidationException::withMessages([
                'qr_code' => 'Registrasi tidak ditemukan.',
            ]);
        }

        return $registration;
    }
}