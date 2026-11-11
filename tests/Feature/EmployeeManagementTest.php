<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['dashboard.view', 'employees.view', 'employees.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function createUser(array $permissions = []): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function employeeData(Department $department, array $overrides = []): array
    {
        return array_merge(
            [
                'nik' => 'EMP001',
                'name' => 'Test Employee',
                'department_id' => $department->id,
                'email' => 'employee@example.com',
                'phone' => '081234567890',
                'is_active' => true,
            ],
            $overrides,
        );
    }

    public function test_guest_cannot_access_employees(): void
    {
        $this->get(route('employees.index'))->assertRedirect(route('login'));
    }

    public function test_authorized_user_can_view_employees(): void
    {
        $user = $this->createUser(['employees.view']);

        Employee::factory()->create([
            'name' => 'Employee Example',
        ]);

        $this->actingAs($user)->get(route('employees.index'))->assertOk()->assertSee('Employee Example');
    }

    public function test_user_without_permission_cannot_view_employees(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->get(route('employees.index'))->assertForbidden();
    }

    public function test_authorized_user_can_open_create_form(): void
    {
        $user = $this->createUser(['employees.manage']);

        $department = Department::factory()->create([
            'name' => 'Information Technology',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('employees.create'))->assertOk()->assertSee('Information Technology');
    }

    public function test_authorized_user_can_create_employee(): void
    {
        $user = $this->createUser(['employees.view', 'employees.manage']);

        $department = Department::factory()->create();

        $this->actingAs($user)->post(route('employees.store'), $this->employeeData($department))->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('employees', [
            'nik' => 'EMP001',
            'name' => 'Test Employee',
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }

    public function test_employee_nik_is_normalized_to_uppercase(): void
    {
        $user = $this->createUser(['employees.manage']);

        $department = Department::factory()->create();

        $this->actingAs($user)
            ->post(
                route('employees.store'),
                $this->employeeData($department, [
                    'nik' => 'emp001',
                ]),
            )
            ->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('employees', [
            'nik' => 'EMP001',
        ]);
    }

    public function test_duplicate_employee_nik_is_rejected(): void
    {
        $user = $this->createUser(['employees.manage']);

        $department = Department::factory()->create();

        Employee::factory()->create([
            'nik' => 'EMP001',
        ]);

        $this->actingAs($user)->post(route('employees.store'), $this->employeeData($department))->assertSessionHasErrors('nik');
    }

    public function test_employee_cannot_be_created_with_inactive_department(): void
    {
        $user = $this->createUser(['employees.manage']);

        $department = Department::factory()->create([
            'is_active' => false,
        ]);

        $this->actingAs($user)->post(route('employees.store'), $this->employeeData($department))->assertSessionHasErrors('department_id');
    }

    public function test_authorized_user_can_update_employee(): void
    {
        $user = $this->createUser(['employees.manage']);

        $department = Department::factory()->create();

        $employee = Employee::factory()->create([
            'department_id' => $department->id,
        ]);

        $this->actingAs($user)
            ->put(
                route('employees.update', $employee),
                $this->employeeData($department, [
                    'nik' => $employee->nik,
                    'name' => 'Updated Employee',
                    'is_active' => false,
                ]),
            )
            ->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'name' => 'Updated Employee',
            'is_active' => false,
        ]);
    }

    public function test_employee_can_keep_current_inactive_department_on_update(): void
    {
        $user = $this->createUser(['employees.manage']);

        $department = Department::factory()->create([
            'is_active' => false,
        ]);

        $employee = Employee::factory()->create([
            'department_id' => $department->id,
        ]);

        $this->actingAs($user)
            ->put(
                route('employees.update', $employee),
                $this->employeeData($department, [
                    'nik' => $employee->nik,
                    'name' => 'Updated Employee',
                ]),
            )
            ->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'department_id' => $department->id,
            'name' => 'Updated Employee',
        ]);
    }

    public function test_employee_cannot_move_to_another_inactive_department(): void
    {
        $user = $this->createUser(['employees.manage']);

        $currentDepartment = Department::factory()->create();

        $inactiveDepartment = Department::factory()->create([
            'is_active' => false,
        ]);

        $employee = Employee::factory()->create([
            'department_id' => $currentDepartment->id,
        ]);

        $this->actingAs($user)
            ->put(
                route('employees.update', $employee),
                $this->employeeData($inactiveDepartment, [
                    'nik' => $employee->nik,
                ]),
            )
            ->assertSessionHasErrors('department_id');
    }

    public function test_user_without_permission_cannot_update_employee(): void
    {
        $user = $this->createUser();

        $employee = Employee::factory()->create();

        $this->actingAs($user)
            ->put(
                route('employees.update', $employee),
                $this->employeeData($employee->department, [
                    'nik' => $employee->nik,
                ]),
            )
            ->assertForbidden();
    }

    public function test_authorized_user_can_delete_employee(): void
    {
        $user = $this->createUser(['employees.manage']);

        $employee = Employee::factory()->create();

        $this->actingAs($user)->delete(route('employees.destroy', $employee))->assertRedirect(route('employees.index'));

        $this->assertDatabaseMissing('employees', [
            'id' => $employee->id,
        ]);
    }

    public function test_user_without_permission_cannot_delete_employee(): void
    {
        $user = $this->createUser();

        $employee = Employee::factory()->create();

        $this->actingAs($user)->delete(route('employees.destroy', $employee))->assertForbidden();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
        ]);
    }

    public function test_inactive_user_cannot_access_employees(): void
    {
        $user = $this->createUser(['employees.view']);

        // Direct assignment avoids mass-assignment restrictions.
        $user->is_active = false;
        $user->save();

        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($user)->get(route('employees.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
