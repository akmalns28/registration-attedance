<?php

namespace App\Http\Controllers;

use App\Models\EventRegistration;
use Illuminate\Contracts\View\View;

class EventRegistrationController extends Controller
{
    public function __invoke(EventRegistration $registration): View
    {
        $registration->loadMissing('event');

        return view('events.registration', [
            'registration' => $registration,
        ]);
    }
}
