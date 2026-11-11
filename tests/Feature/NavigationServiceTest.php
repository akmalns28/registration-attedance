<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NavigationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_sees_permitted_navigation(): void
    {
        Permission::findOrCreate('dashboard.view', 'web');
        Permission::findOrCreate('roles.manage', 'web');

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->givePermissionTo('dashboard.view');

        $this->actingAs($user);

        $navigation = app(NavigationService::class)->getMenu($user);

        $items = collect($navigation)->flatMap(fn($section) => $section['items'])->pluck('label');

        $this->assertTrue($items->contains('Dashboard'));
        $this->assertFalse($items->contains('Role Management'));
    }
}
