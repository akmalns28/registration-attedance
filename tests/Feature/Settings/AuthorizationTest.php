<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['dashboard.view', 'users.manage', 'roles.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = Role::findOrCreate('super-admin', 'web');

        $admin->syncPermissions(['dashboard.view', 'users.manage', 'roles.manage']);

        $panitia = Role::findOrCreate('panitia', 'web');

        $panitia->syncPermissions(['dashboard.view']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createUser(string $role, bool $active = true): User
    {
        $user = User::factory()->create();

        $user->is_active = $active;
        $user->save();

        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_admin_can_access_dashboard(): void
    {
        $admin = $this->createUser('super-admin');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_inactive_user_cannot_access_dashboard(): void
    {
        $user = $this->createUser('super-admin', false);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_admin_can_access_role_management(): void
    {
        $admin = $this->createUser('super-admin');

        $this->actingAs($admin)->get(route('settings.roles.index'))->assertOk();
    }

    public function test_panitia_cannot_access_role_management(): void
    {
        $panitia = $this->createUser('panitia');

        $this->actingAs($panitia)->get(route('settings.roles.index'))->assertForbidden();
    }

    public function test_admin_can_access_user_management(): void
    {
        $admin = $this->createUser('super-admin');

        $this->actingAs($admin)->get(route('settings.users.index'))->assertOk();
    }

    public function test_panitia_cannot_access_user_management(): void
    {
        $panitia = $this->createUser('panitia');

        $this->actingAs($panitia)->get(route('settings.users.index'))->assertForbidden();
    }

    public function test_super_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->createUser('super-admin');

        $role = Role::findByName('super-admin', 'web');

        $this->actingAs($admin)->delete(route('settings.roles.destroy', $role))->assertForbidden();

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'super-admin',
        ]);
    }

    public function test_user_cannot_modify_own_account(): void
    {
        $admin = $this->createUser('super-admin');

        $this->actingAs($admin)
            ->put(route('settings.users.update', $admin), [
                'name' => 'Changed Name',
                'email' => $admin->email,
                'roles' => [Role::findByName('panitia', 'web')->id],
                'is_active' => false,
            ])
            ->assertForbidden();
    }

    public function test_role_permission_changes_are_enforced(): void
    {
        $user = $this->createUser('panitia');

        $role = Role::findByName('panitia', 'web');

        $this->actingAs($user)->get(route('settings.roles.index'))->assertForbidden();

        $role->givePermissionTo('roles.manage');

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        $this->actingAs($user)->get(route('settings.roles.index'))->assertOk();

        $role->revokePermissionTo('roles.manage');

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        $this->actingAs($user)->get(route('settings.roles.index'))->assertForbidden();
    }
}
