<?php

namespace Tests\Feature;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_can_be_created(): void
    {
        $department = Department::factory()->create([
            'code' => 'IT',
            'name' => 'Information Technology',
        ]);

        $this->assertDatabaseHas('departments', [
            'code' => 'IT',
            'name' => 'Information Technology',
        ]);

        $this->assertTrue($department->is_active);
    }

    public function test_department_is_active_by_default(): void
    {
        $department = Department::create([
            'code' => 'HRD',
            'name' => 'Human Resources',
        ]);

        $this->assertTrue($department->fresh()->is_active);
    }

    public function test_active_scope_only_returns_active_departments(): void
    {
        Department::factory()->create([
            'is_active' => true,
        ]);

        Department::factory()->create([
            'is_active' => false,
        ]);

        $this->assertCount(1, Department::active()->get());
    }

    public function test_department_code_must_be_unique(): void
    {
        Department::factory()->create([
            'code' => 'FIN',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Department::factory()->create([
            'code' => 'FIN',
        ]);
    }
}
