<?php

namespace App\Services;

use App\Models\EventRegistration;
use Linkxtr\QrCode\Facades\QrCode;
use Linkxtr\QrCode\Generator;
use RuntimeException;

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
        return (string) $this->pngGenerator()->size(600)->generate($this->payload($registration));
    }

    public function generateInvitationPng(EventRegistration $registration): string
    {
        $png = $this->pngGenerator()->size(256)->color(0, 0, 0)->backgroundColor(255, 255, 255)
            ->withoutCache()->generate($this->payload($registration));
        $image = imagecreatefromstring((string) $png);
        if ($image === false) {
            throw new RuntimeException('Gagal membaca gambar QR undangan.');
        }

        imagepalettetotruecolor($image);
        imagesavealpha($image, false);

        ob_start();
        try {
            if (! imagepng($image)) {
                throw new RuntimeException('Gagal menyimpan gambar QR undangan.');
            }

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function pngGenerator(): Generator
    {
        return QrCode::format('png')->encoding('ISO-8859-1')->margin(4)->errorCorrection('Q');
    }

    /**
     * Generate QR Code filename.
     */
    public function filename(EventRegistration $registration): string
    {
        return 'registration-'.$registration->uuid.'.png';
    }
}
