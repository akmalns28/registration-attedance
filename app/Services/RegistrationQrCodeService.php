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
     */
    public function generatePng(EventRegistration $registration): string
    {
        $result = QrCode::format('png')->size(600)->margin(4)->errorCorrection('M')->generate($this->payload($registration));

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
