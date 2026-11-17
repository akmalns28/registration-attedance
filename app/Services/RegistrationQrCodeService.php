<?php

namespace App\Services;

use App\Models\EventRegistration;
use Linkxtr\QrCode\Facades\QrCode;

class RegistrationQrCodeService
{
    /**
     * Generate registration QR payload.
     */
    public function payload(EventRegistration $registration): string
    {
        return 'RA1:'.$registration->uuid;
    }

    /**
     * Generate PNG QR Code binary.
     * RA1 tokens are ASCII, so an additional UTF-8 ECI segment is unnecessary.
     */
    public function generatePng(EventRegistration $registration): string
    {
        $result = QrCode::format('png')->encoding('ISO-8859-1')->size(600)->margin(4)
            ->errorCorrection('Q')->generate($this->payload($registration));

        return (string) $result;
    }

    /**
     * Generate QR Code filename.
     */
    public function filename(EventRegistration $registration): string
    {
        return 'registration-'.$registration->uuid.'.png';
    }
}
