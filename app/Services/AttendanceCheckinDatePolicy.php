<?php

namespace App\Services;

use App\Models\EventDay;
use Illuminate\Validation\ValidationException;

class AttendanceCheckinDatePolicy
{
    public function ensureAllowed(EventDay $day): void
    {
        $timezone = config('app.timezone');

        if ($day->date->toDateString() !== now($timezone)->toDateString()) {
            throw ValidationException::withMessages([
                'event_day_id' => 'Check-in hanya tersedia pada tanggal '.$day->date->format('d/m/Y').' ('.$timezone.').',
            ]);
        }
    }
}
