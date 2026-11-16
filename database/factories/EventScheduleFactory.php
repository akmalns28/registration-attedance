<?php

namespace Database\Factories;

use App\Models\EventDay;
use App\Models\EventSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSchedule>
 */
class EventScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_day_id' => EventDay::factory(),

            'title' => fake()->sentence(3),

            'description' => fake()->optional()->sentence(),

            'start_time' => '09:00:00',

            'end_time' => '10:00:00',

            'pic' => fake()->optional()->name(),

            'sort_order' => 0,

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
