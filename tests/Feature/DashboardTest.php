<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_counts_existing_registrations_and_day_opportunities_across_events(): void
    {
        $event = Event::factory()->published()->create(['title' => 'Two-day event', 'start_date' => '2027-01-15', 'end_date' => '2027-01-16']);
        $historical = Event::factory()->closed()->create(['title' => 'Historical event']);
        $firstDay = EventDay::factory()->for($event)->create(['date' => '2027-01-15']);
        $secondDay = EventDay::factory()->for($event)->create(['date' => '2027-01-16', 'day_number' => 2, 'is_active' => false]);
        EventDay::factory()->for($historical)->create(['date' => '2027-01-17']);
        $employee = EventRegistration::factory()->for($event)->create();
        $external = EventRegistration::factory()->for($event)->external()->create();
        $cancelled = EventRegistration::factory()->for($event)->cancelled()->create();
        EventRegistration::factory()->for($historical)->for($employee->employee)->create();
        Attendance::factory()->for($employee, 'registration')->for($firstDay)->create();
        Attendance::factory()->for($external, 'registration')->for($firstDay)->create();
        Attendance::factory()->for($employee, 'registration')->for($secondDay)->create();
        Attendance::factory()->for($cancelled, 'registration')->for($firstDay)->create();
        $this->actingAs($this->viewer());

        $dashboard = $this->get(route('dashboard'))->assertOk()
            ->assertSee('data-dashboard-chart="registrations"', false)
            ->assertSee('data-dashboard-chart="attendance"', false)
            ->assertSee('data-dashboard-chart="participants"', false)
            ->assertSee('href="'.route('events.show', $event).'"', false);
        $this->assertSame([
            'total_events' => 2, 'active_events' => 1, 'total_participants' => 2, 'total_registered' => 3,
            'total_attendance' => 4, 'attendance_percentage' => 60.0, 'employee_registrations' => 2, 'external_registrations' => 1,
        ], $dashboard->viewData('stats'));
        $this->assertSame(75.0, $dashboard->viewData('attendanceByEvent')->get($event->uuid)['percentage']);

        $charts = $dashboard->viewData('charts');
        $this->assertSame([['label' => $historical->title, 'count' => 1], ['label' => $event->title, 'count' => 2]], $charts['registrations']);
        $this->assertSame([2, 1, 0], array_column($charts['attendance'], 'present'));
        $this->assertSame([0, 1, 1], array_column($charts['attendance'], 'absent'));
        $this->assertSame([2, 1], $charts['participants']);

        $filtered = $this->get(route('dashboard', ['event' => $event->uuid]))->assertOk()
            ->assertDontSee('href="'.route('events.show', $historical).'"', false);
        $this->assertTrue($filtered->viewData('selectedEvent')->is($event));
        $this->assertSame([$event->id], $filtered->viewData('recentEvents')->modelKeys());
        $this->assertSame([
            'total_events' => 1, 'active_events' => 1, 'total_participants' => 2, 'total_registered' => 2,
            'total_attendance' => 4, 'attendance_percentage' => 75.0, 'employee_registrations' => 1, 'external_registrations' => 1,
        ], $filtered->viewData('stats'));
        $this->assertSame([
            'registrations' => [['label' => $event->title, 'count' => 2]],
            'attendance' => array_slice($charts['attendance'], 0, 2),
            'participants' => [1, 1],
        ], $filtered->viewData('charts'));
    }

    public function test_dashboard_displays_zero_and_empty_states_without_records(): void
    {
        $page = $this->actingAs($this->viewer())->get(route('dashboard'))->assertOk()
            ->assertSee('All Events')->assertSee('Belum ada event.')->assertSee('Belum ada hari pelaksanaan.')
            ->assertSee('Belum ada registrasi aktif.')->assertSee('0%')->assertDontSee('data-dashboard-chart=', false);

        $this->assertSame([0, 0, 0, 0, 0, 0.0, 0, 0], array_values($page->viewData('stats')));
    }

    public function test_recent_events_are_limited_to_the_five_latest_and_event_links_require_permission(): void
    {
        $events = Event::factory()->count(6)->create(['created_at' => '2027-01-15 08:00:00']);
        $viewer = User::factory()->create(['is_active' => true])->givePermissionTo('dashboard.view');

        $page = $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->assertSee($events->last()->title)
            ->assertDontSee('href="'.route('events.show', $events->last()).'"', false);

        $this->assertSame($events->reverse()->take(5)->values()->modelKeys(), $page->viewData('recentEvents')->modelKeys());
        $this->get(route('dashboard', ['event' => Str::uuid7()->toString()]))->assertNotFound();
    }

    public function test_dashboard_requires_authentication_and_the_existing_dashboard_permission(): void
    {
        $this->get(route('dashboard'))->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->create(['is_active' => true]))->get(route('dashboard'))->assertForbidden();
    }

    private function viewer(): User
    {
        return User::factory()->create(['is_active' => true])->assignRole('monitoring');
    }
}
