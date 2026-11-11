<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_created_without_password(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'test@example.com',
            'is_active' => false,
        ]);

        $this->assertNull($user->password);
    }

    public function test_inactive_user_cannot_access_application(): void
    {
        $user = User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
        ]);

        $this->assertFalse(
            $user->canAccessApplication()
        );
    }

    public function test_active_user_loaded_from_database_can_access_application(): void
    {
        $user = User::factory()->create(['is_active' => true])->fresh();

        $this->assertTrue($user->is_active);
        $this->assertTrue($user->canAccessApplication());
    }

    public function test_role_helpers_work_correctly(): void
    {
        Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::create(['name' => 'panitia', 'guard_name' => 'web']);

        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        $user->assignRole('super-admin');

        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertFalse($user->hasRole('panitia'));
    }
}
