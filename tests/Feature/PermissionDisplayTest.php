<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Services\PermissionLabelFormatter;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionDisplayTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['attendance.manual', 'Manual'])]
    #[TestWith(['dashboard.view', 'View'])]
    #[TestWith(['events.create', 'Create'])]
    #[TestWith(['custom.deep.check_in', 'Check In'])]
    #[TestWith(['custom-action', 'Custom Action'])]
    #[TestWith(['unusual.', 'Unusual'])]
    public function test_action_labels_have_readable_fallbacks(string $identifier, string $label): void
    {
        $this->assertSame($label, PermissionLabelFormatter::action($identifier));
    }

    public function test_simplified_labels_preserve_values_selections_and_role_permission_persistence(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super-admin');
        $role = Role::findByName('panitia');
        $html = $this->actingAs($user)->get(route('settings.roles.edit', $role))->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//input[@name="permissions[]"]') as $input) {
            $value = $input->getAttribute('value');
            $this->assertSame(PermissionLabelFormatter::action($value), trim($xpath->query('../span', $input)->item(0)->textContent));
            $this->assertSame($role->hasPermissionTo($value), $input->hasAttribute('checked'));
        }
        $this->put(route('settings.roles.update', $role), ['name' => 'panitia', 'permissions' => ['attendance.scan', 'events.view']])->assertRedirect();
        $this->assertSame(['attendance.scan', 'events.view'], $role->fresh()->permissions->pluck('name')->sort()->values()->all());
        $this->assertDatabaseHas('permissions', ['name' => 'attendance.scan']);
    }
}
