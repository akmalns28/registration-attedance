<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventDay>
 */
class EventDayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),

            'day_number' => 1,

            'date' => '2027-01-15',

            'title' => 'Opening Day',

            'description' => fake()->sentence(),

            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(
            fn () => [
                'is_active' => false,
            ],
        );
    }
}
