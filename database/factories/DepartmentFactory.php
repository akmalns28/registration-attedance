<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('DEP####')),

            'name' => 'Department ' . $this->faker->unique()->uuid(),

            'description' => $this->faker->optional()->sentence(),

            'is_active' => true,
        ];
    }
}
