<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $startDate = now()->addDays(fake()->numberBetween(10, 60))->startOfDay();

        $endDate = $startDate->copy()->addDays(fake()->numberBetween(0, 3));

        return [
            'title' => fake()->sentence(3),

            'description' => fake()->optional()->paragraph(),

            'location' => fake()->city(),

            'start_date' => $startDate->toDateString(),

            'end_date' => $endDate->toDateString(),

            'registration_start_at' => now()->toDateTimeString(),

            'registration_end_at' => $startDate->copy()->subDay()->toDateTimeString(),

            'capacity' => fake()->numberBetween(50, 500),

            'status' => Event::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(
            fn () => [
                'status' => Event::STATUS_PUBLISHED,
            ],
        );
    }

    public function closed(): static
    {
        return $this->state(
            fn () => [
                'status' => Event::STATUS_CLOSED,
            ],
        );
    }

    public function cancelled(): static
    {
        return $this->state(
            fn () => [
                'status' => Event::STATUS_CANCELLED,
            ],
        );
    }
}
