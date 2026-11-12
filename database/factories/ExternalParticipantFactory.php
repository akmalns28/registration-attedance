<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ExternalParticipant>
 */
class ExternalParticipantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),

            'email' => $this->faker->boolean(70) ? $this->faker->safeEmail() : null,

            'phone' => $this->faker->boolean(70) ? $this->faker->numerify('08##########') : null,

            'organization' => $this->faker->boolean(70) ? $this->faker->company() : null,

            'position' => $this->faker->boolean(50) ? $this->faker->jobTitle() : null,

            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(
            fn() => [
                'is_active' => false,
            ],
        );
    }
}
