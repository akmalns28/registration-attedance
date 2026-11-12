<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nip' => $this->faker->unique()->numerify('EMP############'),

            'name' => $this->faker->name(),

            'department_id' => Department::factory(),

            'email' => $this->faker->boolean(70) ? $this->faker->unique()->safeEmail() : null,

            'phone' => $this->faker->boolean(70) ? $this->faker->numerify('08##########') : null,

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
