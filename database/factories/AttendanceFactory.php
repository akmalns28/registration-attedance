<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_registration_id' => EventRegistration::factory()->for(Event::factory()->published()),
            'event_day_id' => function (array $attributes): int {
                $event = EventRegistration::query()->findOrFail($attributes['event_registration_id'])->event;

                return EventDay::factory()->for($event)->create(['date' => $event->start_date->toDateString()])->id;
            },
            'checked_in_by' => User::factory(),
            'checked_in_at' => now(),
        ];
    }
}
