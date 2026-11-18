<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_dashboard_renders_navigation_and_the_spatie_role_in_the_account_menu(): void
    {
        $applicationName = 'Event & Attendance <Portal>';
        config(['app.name' => $applicationName]);
        $user = $this->userWithRole('super-admin');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-bs-navbar-position="combined"', false)
            ->assertSeeInOrder([
                '<title>', e($applicationName), '</title>', '<aside', e($applicationName),
                '<header', '<div class="page-wrapper">', 'page-pretitle', e($applicationName),
            ], false)
            ->assertSee('aria-controls="sidebar-menu"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('href="'.route('settings.roles.index').'"', false)
            ->assertSee('href="'.route('events.index').'"', false)
            ->assertSee('href="'.route('attendance.scanner').'"', false)
            ->assertSee('Super Admin')
            ->assertSee($user->email)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertDontSee('Registration</span>', false);
    }

    public function test_login_and_error_pages_render_the_configured_application_name(): void
    {
        $applicationName = 'Event & Attendance <Portal>';
        config(['app.name' => $applicationName]);
        $branding = ['<title>', e($applicationName), '</title>', e($applicationName), 'PT Ari Utsaha Nusantara'];

        $this->get(route('login'))->assertOk()->assertSeeInOrder($branding, false);
        $this->get('/route-yang-tidak-tersedia')->assertNotFound()->assertSeeInOrder($branding, false);
    }

    public function test_staff_navigation_hides_role_management_and_the_route_still_denies_access(): void
    {
        $this->actingAs($this->userWithRole('panitia'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('events.index').'"', false)
            ->assertSee('href="'.route('attendance.scanner').'"', false)
            ->assertDontSee('href="'.route('settings.roles.index').'"', false)
            ->assertSee('Panitia');

        $this->get(route('settings.roles.index'))->assertForbidden();
    }

    public function test_role_pages_render_the_shared_layout_and_active_navigation(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));
        $role = Role::findByName('panitia', 'web');

        foreach ([route('settings.roles.index'), route('settings.roles.create'), route('settings.roles.edit', $role)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('href="'.route('settings.roles.index').'"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee('Account navigation');
        }
    }

    public function test_authenticated_home_redirects_to_the_dashboard(): void
    {
        $this->actingAs($this->userWithRole('panitia'))->get('/')->assertRedirectToRoute('dashboard');
    }

    public function test_attendance_scanner_keeps_the_shared_application_layout(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        $this->get(route('attendance.scanner'))->assertOk()
            ->assertSee('aria-label="Main navigation"', false)
            ->assertSee('aria-label="Account navigation"', false)
            ->assertSee('data-attendance-scanner', false)
            ->assertSee('Kehadiran belum dicatat.')
            ->assertDontSee('Fitur belum tersedia');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_event_management_pages_keep_the_application_navigation(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        foreach (['events.index', 'events.create'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('aria-label="Main navigation"', false)->assertSee('aria-label="Account navigation"', false)->assertDontSee('Fitur belum tersedia');
        }
    }
}
