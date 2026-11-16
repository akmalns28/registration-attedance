<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\RegistrationQrCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventRegistrationQrController extends Controller
{
    public function __construct(private readonly RegistrationQrCodeService $qrCode) {}

    public function __invoke(Request $request, Event $event, EventRegistration $registration): Response
    {
        abort_unless((int) $registration->event_id === (int) $event->id, 404);

        return response($this->qrCode->generatePng($registration), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$this->qrCode->filename($registration).'"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
