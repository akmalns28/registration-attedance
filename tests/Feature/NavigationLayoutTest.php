<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMXPath;
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

    public function test_breadcrumbs_follow_event_days_and_rundown_forms(): void
    {
        $event = Event::factory()->create(['title' => 'Briefing & <Operations>']);
        $day = EventDay::factory()->for($event)->create(['title' => 'Opening']);
        $this->actingAs($this->userWithRole('super-admin'));
        $parents = [
            'Home' => route('dashboard'), 'Events' => route('events.index'), $event->title => route('events.show', $event),
        ];

        $this->assertBreadcrumbs(route('events.days.show', [$event, $day]), [...$parents, 'Day '.$day->day_number.' - Opening' => '']);
        $this->assertBreadcrumbs(route('events.days.schedules.create', [$event, $day]), [
            ...$parents, 'Day '.$day->day_number.' - Opening' => route('events.days.show', [$event, $day]), 'Tambah Rundown' => '',
        ]);
    }

    public function test_shared_breadcrumb_replaces_page_specific_trails_and_links_to_accessible_parents(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));
        $home = ['Home' => route('dashboard')];

        $this->assertBreadcrumbs(route('dashboard'), ['Home' => '']);
        $this->assertBreadcrumbs(route('employees.create'), [...$home, 'Master Data' => '', 'Employees' => route('employees.index'), 'Tambah Karyawan' => '']);
        $this->assertBreadcrumbs(route('settings.users.index'), [...$home, 'Administration' => '', 'User Management' => '']);
        $this->assertBreadcrumbs(route('settings.roles.create'), [...$home, 'Administration' => '', 'Role Management' => route('settings.roles.index'), 'Tambah Role' => '']);

        $user = User::factory()->create(['is_active' => true])->givePermissionTo('employees.manage');
        $this->actingAs($user);
        $this->assertBreadcrumbs(route('employees.create'), ['Home' => '', 'Master Data' => '', 'Employees' => '', 'Tambah Karyawan' => '']);
    }

    public function test_footer_uses_the_configured_version_and_renders_one_contact_dialog(): void
    {
        $this->travelTo(now()->setDate(2031, 6, 1));
        config(['app.version' => '2.4.8']);
        $html = $this->actingAs($this->userWithRole('super-admin'))->get(route('dashboard'))->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $footer = $xpath->query('//footer')->item(0);

        $this->assertStringContainsString('© 2031 PT Ari Utsaha Nusantara · RAMS v2.4.8', $footer->textContent);
        $this->assertSame(1, $xpath->query('.//a[@href="#contactUsModal"]', $footer)->length);
        $this->assertSame(1, $xpath->query('//*[@id="contactUsModal"]')->length);
        $modal = $xpath->query('//*[@id="contactUsModal"]')->item(0);
        $this->assertSame('contactUsModal-label', $modal->getAttribute('aria-labelledby'));
        $this->assertStringContainsString('+62 811-1118-9864', $modal->textContent);
        $this->assertSame(1, $xpath->query('.//a[@href="https://wa.me/6281111189864"]', $modal)->length);
        $this->assertSame(2, $xpath->query('.//a[@href="mailto:it@ptaun.com"]', $modal)->length);
    }

    /** @param array<string, string> $expected */
    private function assertBreadcrumbs(string $url, array $expected): void
    {
        $html = $this->get($url)->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $nav = '//nav[@aria-label="breadcrumb"]';
        $this->assertSame(1, $xpath->query($nav)->length);

        $items = [];
        foreach ($xpath->query($nav.'//li') as $item) {
            $items[trim($item->textContent)] = $xpath->evaluate('string(a/@href)', $item);
        }
        $this->assertSame($expected, $items);
        $this->assertSame(1, $xpath->query($nav.'//li[last()][@aria-current="page" and not(a)]')->length);
    }
}
