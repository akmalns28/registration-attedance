<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ExternalParticipant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_belongs_to_department(): void
    {
        $department = Department::factory()->create([
            'code' => 'IT',
            'name' => 'Information Technology',
        ]);

        $employee = Employee::factory()->create([
            'nip' => 'EMP000000000001',
            'department_id' => $department->id,
        ]);

        $this->assertEquals($department->id, $employee->department->id);

        $this->assertEquals('Information Technology', $employee->department->name);
    }

    public function test_department_has_multiple_employees(): void
    {
        $department = Department::factory()->create();

        Employee::factory()
            ->count(3)
            ->create([
                'department_id' => $department->id,
            ]);

        $this->assertEquals(3, $department->employees()->count());
    }

    public function test_department_with_employees_cannot_be_deleted(): void
    {
        $department = Department::factory()->create();

        Employee::factory()->create([
            'department_id' => $department->id,
        ]);

        try {
            $department->delete();

            $this->fail('Department with employees should not be deleted.');
        } catch (QueryException $exception) {
            $this->assertDatabaseHas('departments', [
                'id' => $department->id,
            ]);
        }
    }

    public function test_deactivating_department_does_not_delete_employees(): void
    {
        $department = Department::factory()->create();

        $employee = Employee::factory()->create([
            'department_id' => $department->id,
        ]);

        $department->update([
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_deactivating_employee_does_not_affect_department(): void
    {
        $department = Department::factory()->create();

        $employee = Employee::factory()->create([
            'department_id' => $department->id,
        ]);

        $employee->update([
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'is_active' => false,
        ]);
    }

    public function test_external_participant_is_independent_from_department(): void
    {
        $participant = ExternalParticipant::factory()->create([
            'name' => 'External Guest',
        ]);

        $this->assertDatabaseHas('external_participants', [
            'id' => $participant->id,
            'name' => 'External Guest',
        ]);

        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_master_data_active_scopes_are_independent(): void
    {
        $department = Department::factory()->create([
            'is_active' => true,
        ]);

        Employee::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        Employee::factory()->create([
            'department_id' => $department->id,
            'is_active' => false,
        ]);

        ExternalParticipant::factory()->create([
            'is_active' => true,
        ]);

        ExternalParticipant::factory()->create([
            'is_active' => false,
        ]);

        $this->assertEquals(1, Department::active()->count());
        $this->assertEquals(1, Employee::active()->count());
        $this->assertEquals(1, ExternalParticipant::active()->count());
    }

    public function test_employee_department_relationship_uses_eager_loading(): void
    {
        $department = Department::factory()->create();

        Employee::factory()
            ->count(5)
            ->create([
                'department_id' => $department->id,
            ]);

        $employees = Employee::query()->with('department')->get();

        $this->assertCount(5, $employees);

        foreach ($employees as $employee) {
            $this->assertTrue($employee->relationLoaded('department'));
        }
    }
}
