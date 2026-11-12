<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MasterDataAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['departments.view', 'departments.create', 'departments.update', 'departments.delete', 'employees.view', 'employees.manage', 'external-participants.view', 'external-participants.manage'] as $permission) {
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

    public function test_user_without_permissions_cannot_access_master_data(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        foreach (['departments.index', 'employees.index', 'external-participants.index'] as $routeName) {
            $this->get(route($routeName))->assertForbidden();
        }
    }

    public function test_department_view_permission_does_not_grant_employee_access(): void
    {
        $user = $this->createUser(['departments.view']);

        $this->actingAs($user);

        $this->get(route('departments.index'))->assertOk();

        $this->get(route('employees.index'))->assertForbidden();

        $this->get(route('external-participants.index'))->assertForbidden();
    }

    public function test_employee_view_permission_does_not_grant_management_access(): void
    {
        $user = $this->createUser(['employees.view']);

        $this->actingAs($user);

        $this->get(route('employees.index'))->assertOk();

        $this->get(route('employees.create'))->assertForbidden();
    }

    public function test_external_participant_view_permission_does_not_grant_management_access(): void
    {
        $user = $this->createUser(['external-participants.view']);

        $this->actingAs($user);

        $this->get(route('external-participants.index'))->assertOk();

        $this->get(route('external-participants.create'))->assertForbidden();
    }

    public function test_user_with_all_master_data_permissions_can_access_all_modules(): void
    {
        $user = $this->createUser(['departments.view', 'departments.create', 'departments.update', 'departments.delete', 'employees.view', 'employees.manage', 'external-participants.view', 'external-participants.manage']);

        $this->actingAs($user);

        foreach (['departments.index', 'departments.create', 'employees.index', 'employees.create', 'external-participants.index', 'external-participants.create'] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }
    }

    public function test_inactive_user_cannot_access_any_master_data_module(): void
    {
        $user = $this->createUser(['departments.view', 'employees.view', 'external-participants.view']);

        $user->is_active = false;
        $user->save();

        $this->assertFalse($user->fresh()->is_active);

        foreach (['departments.index', 'employees.index', 'external-participants.index'] as $routeName) {
            $this->actingAs($user)->get(route($routeName))->assertRedirect(route('login'));
        }
    }
}
