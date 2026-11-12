<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_be_created(): void
    {
        $department = Department::factory()->create();

        $employee = Employee::factory()->create([
            'nip' => 'EMP000000000001',
            'name' => 'Test Employee',
            'department_id' => $department->id,
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nip' => 'EMP000000000001',
            'name' => 'Test Employee',
            'department_id' => $department->id,
        ]);
    }

    public function test_employee_belongs_to_department(): void
    {
        $department = Department::factory()->create([
            'name' => 'Information Technology',
        ]);

        $employee = Employee::factory()->create([
            'department_id' => $department->id,
        ]);

        $this->assertInstanceOf(
            Department::class,
            $employee->department
        );

        $this->assertEquals(
            $department->id,
            $employee->department->id
        );
    }

    public function test_department_has_many_employees(): void
    {
        $department = Department::factory()->create();

        Employee::factory()
            ->count(3)
            ->create([
                'department_id' => $department->id,
            ]);

        $this->assertCount(
            3,
            $department->employees
        );
    }

    public function test_employee_is_active_by_default(): void
    {
        $employee = Employee::factory()->create();

        $this->assertTrue($employee->is_active);
    }

    public function test_active_scope_only_returns_active_employees(): void
    {
        Employee::factory()->create([
            'is_active' => true,
        ]);

        Employee::factory()->inactive()->create();

        $this->assertCount(
            1,
            Employee::active()->get()
        );
    }

    public function test_employee_nip_must_be_unique(): void
    {
        Employee::factory()->create([
            'nip' => 'EMP000000000001',
        ]);

        $this->expectException(
            QueryException::class
        );

        Employee::factory()->create([
            'nip' => 'EMP000000000001',
        ]);
    }

    public function test_employee_identifier_migration_preserves_data_and_can_be_reversed(): void
    {
        $employee = Employee::factory()->create(['nip' => '001234567890123']);
        $migration = require database_path('migrations/2026_11_12_142733_rename_employee_nik_to_nip.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('employees', 'nip'));
        $this->assertTrue(Schema::hasIndex('employees', 'employees_nik_unique', 'unique'));
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'nik' => '001234567890123',
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('employees', 'nik'));
        $this->assertTrue(Schema::hasIndex('employees', 'employees_nip_unique', 'unique'));
        $this->assertSame('001234567890123', $employee->fresh()->nip);
    }

    public function test_department_with_employees_cannot_be_deleted(): void
    {
        $department = Department::factory()->create();

        Employee::factory()->create([
            'department_id' => $department->id,
        ]);

        $this->expectException(
            QueryException::class
        );

        $department->delete();
    }
}
