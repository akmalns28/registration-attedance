<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['dashboard.view', 'departments.view', 'departments.create', 'departments.update', 'departments.delete'] as $permission) {
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

    private function departmentData(array $overrides = []): array
    {
        return array_merge(
            [
                'code' => 'IT',
                'name' => 'Information Technology',
                'description' => 'Departemen IT',
                'is_active' => true,
            ],
            $overrides,
        );
    }

    public function test_guest_cannot_access_departments(): void
    {
        $this->get(route('departments.index'))->assertRedirect(route('login'));
    }

    public function test_authorized_user_can_view_departments(): void
    {
        $user = $this->createUser(['departments.view']);

        Department::factory()->create([
            'code' => 'HRD',
            'name' => 'Human Resources',
        ]);

        $this->actingAs($user)->get(route('departments.index'))->assertOk()->assertSee('Human Resources');
    }

    public function test_user_without_permission_cannot_view_departments(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->get(route('departments.index'))->assertForbidden();
    }

    public function test_authorized_user_can_create_department(): void
    {
        $user = $this->createUser(['departments.create']);

        $this->actingAs($user)->post(route('departments.store'), $this->departmentData())->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', [
            'code' => 'IT',
            'name' => 'Information Technology',
            'is_active' => true,
        ]);
    }

    public function test_department_code_is_normalized_to_uppercase(): void
    {
        $user = $this->createUser(['departments.create']);

        $this->actingAs($user)
            ->post(
                route('departments.store'),
                $this->departmentData([
                    'code' => 'hrd',
                    'name' => 'Human Resources',
                ]),
            )
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', [
            'code' => 'HRD',
        ]);
    }

    public function test_duplicate_department_code_is_rejected(): void
    {
        $user = $this->createUser(['departments.create']);

        Department::factory()->create([
            'code' => 'IT',
        ]);

        $this->actingAs($user)->post(route('departments.store'), $this->departmentData())->assertSessionHasErrors('code');
    }

    public function test_authorized_user_can_update_department(): void
    {
        $user = $this->createUser(['departments.update']);

        $department = Department::factory()->create([
            'code' => 'IT',
            'name' => 'Information Technology',
        ]);

        $this->actingAs($user)
            ->put(
                route('departments.update', $department),
                $this->departmentData([
                    'code' => 'ITS',
                    'name' => 'IT Support',
                    'is_active' => false,
                ]),
            )
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'code' => 'ITS',
            'name' => 'IT Support',
            'is_active' => false,
        ]);
    }

    public function test_user_without_permission_cannot_update_department(): void
    {
        $user = $this->createUser();

        $department = Department::factory()->create();

        $this->actingAs($user)->put(route('departments.update', $department), $this->departmentData())->assertForbidden();
    }

    public function test_authorized_user_can_delete_unused_department(): void
    {
        $user = $this->createUser(['departments.delete']);

        $department = Department::factory()->create();

        $this->actingAs($user)->delete(route('departments.destroy', $department))->assertRedirect(route('departments.index'));

        $this->assertDatabaseMissing('departments', [
            'id' => $department->id,
        ]);
    }

    public function test_user_without_permission_cannot_delete_department(): void
    {
        $user = $this->createUser();

        $department = Department::factory()->create();

        $this->actingAs($user)->delete(route('departments.destroy', $department))->assertForbidden();

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
        ]);
    }

    public function test_inactive_user_cannot_access_departments(): void
    {
        $user = $this->createUser(['departments.view']);

        // Gunakan assignment langsung karena is_active
        // tidak termasuk atribut fillable.
        $user->is_active = false;
        $user->save();

        // Pastikan perubahan benar-benar tersimpan.
        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($user)->get(route('departments.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
