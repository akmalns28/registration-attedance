<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRegistration>
 */
class EventRegistrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),

            'employee_id' => Employee::factory(),

            'external_participant_id' => null,

            'registration_type' => EventRegistration::TYPE_EMPLOYEE,

            'registration_source' => EventRegistration::SOURCE_ADMIN,

            'status' => EventRegistration::STATUS_REGISTERED,

            'registered_at' => now(),

            'cancelled_at' => null,
        ];
    }

    public function employee(): static
    {
        return $this->state(
            fn () => [
                'employee_id' => Employee::factory(),

                'external_participant_id' => null,

                'registration_type' => EventRegistration::TYPE_EMPLOYEE,
            ],
        );
    }

    public function external(): static
    {
        return $this->state(
            fn () => [
                'employee_id' => null,

                'external_participant_id' => ExternalParticipant::factory(),

                'registration_type' => EventRegistration::TYPE_EXTERNAL,
            ],
        );
    }

    public function cancelled(): static
    {
        return $this->state(
            fn () => [
                'status' => EventRegistration::STATUS_CANCELLED,

                'cancelled_at' => now(),
            ],
        );
    }

    public function selfRegistered(): static
    {
        return $this->state(
            fn () => [
                'registration_source' => EventRegistration::SOURCE_SELF,
            ],
        );
    }

    public function invitedTo(EventDay ...$days): static
    {
        return $this->afterCreating(function (EventRegistration $registration) use ($days): void {
            if ($days === []) {
                $event = $registration->event;
                $days = [$event->days()->firstOrCreate(
                    ['date' => $event->start_date->toDateString()],
                    ['day_number' => 1, 'is_active' => true],
                )];
            }

            $registration->invitedDays()->attach(array_map(fn (EventDay $day): int => $day->id, $days));
        });
    }
}
