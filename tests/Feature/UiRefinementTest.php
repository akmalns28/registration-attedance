<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventMedia;
use App\Models\EventPoster;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\EventSchedule;
use App\Models\ExternalParticipant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UiRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
    }

    #[TestWith(['super-admin'])]
    #[TestWith(['event-admin'])]
    #[TestWith(['monitoring'])]
    #[TestWith(['panitia'])]
    public function test_email_management_has_one_authorized_bell_before_the_profile_and_no_unread_badge(string $role): void
    {
        $this->actingAs($this->user($role));
        $html = $this->get(route('events.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(0, $xpath->query('//aside//a[@href="'.route('notifications.index').'"]')->length);
        $this->assertSame(1, $xpath->query('//header//a[@data-notification-entry and @href="'.route('notifications.index').'"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-notification-entry]/following-sibling::*[1]//*[@id="account-menu-toggle"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-notification-entry]//*[contains(@class,"badge")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-notification-entry]/*[local-name()="svg"]')->length);
        $this->get(route('notifications.index'))->assertOk();
    }

    public function test_users_without_event_access_have_no_bell_and_cannot_open_notification_management(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['dashboard.view', 'reports.view']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('data-notification-entry', false);
        $this->getJson(route('notifications.index'))->assertForbidden();
    }

    #[TestWith(['events'])]
    #[TestWith(['employees'])]
    #[TestWith(['external'])]
    #[TestWith(['departments'])]
    #[TestWith(['users'])]
    #[TestWith(['roles'])]
    #[TestWith(['event'])]
    #[TestWith(['day'])]
    #[TestWith(['notifications'])]
    public function test_compact_actions_have_real_svg_labels_tooltips_and_keep_delete_confirmation(string $page): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());
        $xpath = $this->xpath($this->get($this->pages($fixture)[$page])->assertOk()->getContent());
        $actions = $xpath->query('//*[@data-row-action]');
        $this->assertGreaterThan(0, $actions->length);
        foreach ($actions as $action) {
            $this->assertNotSame('', trim($action->getAttribute('aria-label')));
            $this->assertSame($action->getAttribute('aria-label'), $action->getAttribute('title'));
            $this->assertSame('', trim($action->textContent));
            $this->assertSame(1, $xpath->query('./*[local-name()="svg" and @aria-hidden="true"]', $action)->length);
            $this->assertGreaterThan(0, $xpath->query('./*[local-name()="svg"]/*', $action)->length);
            if (str_starts_with($action->getAttribute('aria-label'), 'Hapus') || str_starts_with($action->getAttribute('aria-label'), 'Batalkan')) {
                $this->assertSame('modal', $action->getAttribute('data-bs-toggle'));
                $target = ltrim($action->getAttribute('data-bs-target'), '#');
                $this->assertSame(1, $xpath->query('//*[@id="'.$target.'"]')->length);
            }
        }
        $this->assertSame(0, $xpath->query('//i[contains(@class,"ti-")]')->length);
    }

    public function test_view_only_users_keep_detail_actions_and_cannot_see_edit_delete_or_export_controls(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user('monitoring'));
        $response = $this->get(route('events.show', $fixture['event']))->assertOk()->assertSee('Detail Event Day')
            ->assertDontSee('data-delete-url', false)->assertDontSee('data-cancel-url', false)->assertDontSee('data-report-export=', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@id="eventParticipantsTable" and @data-export="false"]')->length);
        $this->get(route('events.index'))->assertOk()->assertSee('data-export="false"', false)->assertDontSee('aria-label="Edit Event"', false);
    }

    public function test_organization_export_uses_the_existing_department_permission_and_other_tables_require_reports_export(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['departments.view', 'employees.view']);
        $this->actingAs($user)->get(route('departments.index'))->assertOk()->assertSee('data-export="false"', false);
        $this->get(route('organizations.export'))->assertForbidden();
        $user->givePermissionTo('departments.export');
        $this->get(route('departments.index'))->assertOk()->assertSee('data-export="true"', false);
        $this->get(route('employees.index'))->assertOk()->assertSee('data-export="false"', false);
        $user->givePermissionTo('reports.export');
        $this->get(route('employees.index'))->assertOk()->assertSee('data-export="true"', false);
    }

    public function test_svg_catalog_has_a_safe_fallback_and_never_uses_the_icon_name_as_markup(): void
    {
        $html = Blade::render('<x-ui.icon :name="$name" />', ['name' => '"><script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $html);
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//*[local-name()="svg" and @aria-hidden="true"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[local-name()="svg"]/*')->length);
    }

    public function test_dashboard_keeps_statistics_and_quick_actions_without_obsolete_preview_copy(): void
    {
        $this->actingAs($this->user())->get(route('dashboard'))->assertOk()
            ->assertSee('Total Events')->assertSee('Total Participants')->assertSee('Quick Actions')
            ->assertDontSee('Dashboard Preview')->assertDontSee('Data tersedia');
    }

    public function test_disabled_link_buttons_keep_their_label_without_a_clickable_destination(): void
    {
        $xpath = $this->xpath(Blade::render('<x-ui.button href="/restricted" disabled>Unduh</x-ui.button>'));
        $this->assertSame(1, $xpath->query('//a[@aria-disabled="true" and @tabindex="-1" and not(@href)]')->length);
        $this->assertSame('Unduh', trim($xpath->query('//a')->item(0)->textContent));
    }

    public function test_browser_fixtures_cover_management_tables_and_navigation(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());
        $directory = getenv('UI_BROWSER_ARTIFACT_DIR');
        if (! is_string($directory) || ! is_dir($directory)) {
            $this->get(route('events.show', $fixture['event']))->assertOk();

            return;
        }
        Vite::useHotFile($directory.'/vite.hot');
        $entity = Entity::factory()->create(['code' => 'UI', 'name' => 'UI Entity']);
        $names = ['=1+1', '+SUM(1,2)', '-1+1', '@SUM(1,2)', '  =1+1', 'Doe, "Jane"; café', '<img src=x onerror="window.uiXss=true">', 'Peserta Indonesia Éka', 'Participant Nine', 'Participant Ten', 'Participant Eleven', 'Participant Twelve'];
        foreach ($names as $index => $name) {
            $department = Department::factory()->for($entity)->create(['name' => $name, 'code' => 'UI'.$index]);
            $division = Division::factory()->for($department)->create();
            $employee = Employee::factory()->for($department)->for($division)->create(['name' => $name]);
            if ($name === 'Participant Twelve') {
                $employee->update(['email' => 'browser-participant@example.test']);
            }
            ExternalParticipant::factory()->create(['name' => $name]);
            Event::factory()->create(['title' => $name]);
            EventRegistration::factory()->for($fixture['event'])->for($employee)->invitedTo($fixture['day'])->create();
            User::factory()->create(['name' => $name])->assignRole('panitia');
            Role::create(['name' => 'ui-role-'.$index, 'guard_name' => 'web']);
        }
        $fixture['day']->update(['date' => $fixture['event']->start_date->toDateString()]);
        $fixture['event']->update(['status' => Event::STATUS_PUBLISHED]);
        $poster = EventPoster::factory()->for($fixture['event'])->primary()->create(['original_filename' => 'Poster awal.png']);
        $photo = EventMedia::factory()->for($fixture['event'])->create(['title' => 'Foto dokumentasi']);
        $clip = EventMedia::factory()->for($fixture['event'])->create([
            'title' => 'Klip dokumentasi', 'media_type' => 'video', 'mime_type' => 'video/webm', 'original_filename' => 'clip.webm',
        ]);
        $samplePhoto = UploadedFile::fake()->image('photo.png', 40, 60);
        copy($samplePhoto->getPathname(), $directory.'/gallery-photo.png');
        $media = [
            'poster' => route('events.posters.show', [$fixture['event'], $poster]),
            'photo' => route('events.gallery.show', [$fixture['event'], $photo]),
            'clip' => route('events.gallery.show', [$fixture['event'], $clip]),
        ];
        $upload = UploadedFile::fake()->createWithContent('mobile-preview.csv', "nip,name,entity,department,division,email,phone,status\n"
            ."90123,Mobile Preview,UI,UI0,,,08123456789,active\n"
            ."90124,Mobile Validation,UI,MISSING,,,,active\n");
        $preview = $this->post(route('employees.import.preview'), ['file' => $upload])->assertRedirect();
        $pages = $this->pages($fixture) + [
            'dashboard' => route('dashboard'),
            'analytic' => route('analytic.index'),
            'scanner' => route('attendance.scanner'),
            'eventCreate' => route('events.create'),
            'eventEdit' => route('events.edit', $fixture['event']),
            'dayCreate' => route('events.days.create', $fixture['event']),
            'dayEdit' => route('events.days.edit', [$fixture['event'], $fixture['day']]),
            'employeeForm' => route('employees.create'),
            'employeeEdit' => route('employees.edit', $employee),
            'externalForm' => route('external-participants.create'),
            'organizations' => route('organizations.index'),
            'organizationForm' => route('organizations.create'),
            'organizationEdit' => route('organizations.edit', $entity),
            'import' => route('employees.import.index'),
            'importPreview' => $preview->headers->get('Location'),
        ];
        foreach ($pages as $key => $url) {
            if ($key === 'dashboard') {
                $this->withSession(['success' => 'Pengaturan berhasil disimpan.']);
            }
            $response = $this->get($url)->assertOk();
            file_put_contents($directory.'/'.$key.'.html', $response->getContent());
        }
        file_put_contents($directory.'/fixture.json', json_encode(['origin' => url('/'), 'pages' => $pages, 'names' => $names, 'media' => $media], JSON_THROW_ON_ERROR));
    }

    private function user(string $role = 'super-admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    /** @param array{event: Event, day: EventDay} $fixture
     * @return array<string, string>
     */
    private function pages(array $fixture): array
    {
        return ['events' => route('events.index'), 'employees' => route('employees.index'), 'external' => route('external-participants.index'),
            'departments' => route('departments.index'), 'users' => route('settings.users.index'), 'roles' => route('settings.roles.index'),
            'event' => route('events.show', $fixture['event']), 'day' => route('events.days.show', [$fixture['event'], $fixture['day']]),
            'notifications' => route('notifications.index')];
    }

    /** @return array{event: Event, day: EventDay} */
    private function fixture(): array
    {
        $this->user('panitia');
        $event = Event::factory()->create(['title' => 'UI Refinement', 'capacity' => 100]);
        $day = EventDay::factory()->for($event)->create();
        EventSchedule::factory()->create(['event_day_id' => $day->id]);
        $department = Department::factory()->create();
        $employee = Employee::factory()->for($department)->create();
        $registration = EventRegistration::factory()->for($event)->for($employee)->invitedTo($day)->create();
        EventRegistration::factory()->external()->for($event)
            ->invitedTo($day)->create(['external_participant_id' => ExternalParticipant::factory()->state(['email' => null])]);
        EventRegistration::factory()->cancelled()->for($event)->create();
        EventRegistrationNotification::factory()->sent()->create(['event_registration_id' => $registration->id]);

        return compact('event', 'day');
    }
}
