<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ManualAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2027-01-15 09:30:00', config('app.timezone')));
    }

    #[TestWith(['employee', '00123'])]
    #[TestWith(['employee', 'Ayu'])]
    #[TestWith(['external', 'Guest'])]
    #[TestWith(['external', '08127'])]
    public function test_search_matches_the_selected_type_and_discloses_only_its_identifiers(string $type, string $search): void
    {
        [$event, $day] = $this->eventDay();
        $employee = Employee::factory()->for(Department::factory()->create(['name' => 'Operations']))->create(['name' => 'Ayu Employee', 'nip' => '0012345']);
        $internal = EventRegistration::factory()->for($event)->invitedTo($day)->for($employee)->create();
        $external = EventRegistration::factory()->external()->for($event)->invitedTo($day)->create();
        $external->externalParticipant->update(['name' => 'Budi Guest', 'phone' => '0812799999']);
        Attendance::factory()->create(['event_registration_id' => $external->id, 'event_day_id' => $day->id]);

        $response = $this->actingAs($this->operator())->getJson($this->searchUrl($event, $day, $type, $search))->assertOk();

        if ($type === 'employee') {
            $response->assertExactJson(['data' => [[
                'registration_id' => $internal->id, 'participant_name' => 'Ayu Employee', 'participant_type' => 'employee',
                'attendance_status' => 'not_present', 'nip' => '0012345', 'department' => 'Operations',
            ]]]);
        } else {
            $response->assertExactJson(['data' => [[
                'registration_id' => $external->id, 'participant_name' => 'Budi Guest', 'participant_type' => 'external',
                'attendance_status' => 'present', 'phone' => '0812799999',
            ]]]);
        }
    }

    public function test_search_excludes_unregistered_cancelled_inactive_and_other_event_participants(): void
    {
        [$event, $day] = $this->eventDay();
        $matching = EventRegistration::factory()->external()->for($event)->invitedTo($day)->create();
        $cancelled = EventRegistration::factory()->external()->cancelled()->for($event)->invitedTo($day)->create();
        $inactive = EventRegistration::factory()->external()->for($event)->invitedTo($day)->create();
        $other = EventRegistration::factory()->external()->create();
        foreach ([$matching, $cancelled, $inactive, $other] as $registration) {
            $registration->externalParticipant->update(['name' => 'Common Guest', 'phone' => null]);
        }
        $inactive->externalParticipant->update(['is_active' => false]);
        ExternalParticipant::factory()->create(['name' => 'Common Guest']);
        EventRegistration::factory()->for($event)->invitedTo($day)->for(Employee::factory()->create(['nip' => 'EmployeeOnly007']))->create();
        $this->actingAs($this->operator());

        $this->getJson($this->searchUrl($event, $day, 'external', 'Common Guest'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.registration_id', $matching->id)->assertJsonPath('data.0.phone', null);
        $this->getJson($this->searchUrl($event, $day, 'external', 'EmployeeOnly007'))->assertOk()->assertExactJson(['data' => []]);
        $this->getJson($this->searchUrl($event, $day, 'external', 'C'))->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    public function test_manual_checkin_is_available_in_both_scanners_and_locks_the_event_day_context(): void
    {
        [$event, $day] = $this->eventDay();
        $this->actingAs($this->operator());

        $this->get(route('attendance.scanner'))->assertOk()->assertSeeInOrder(['Scan QR', 'Manual Check-in'])
            ->assertSee('id="manual-event"', false)->assertSee('id="manual-day"', false);
        $this->get(route('events.days.show', [$event, $day]))->assertOk()
            ->assertSeeInOrder(['id="day-rundown-tab"', 'id="day-scanner-tab"', 'id="day-manual-tab"', 'id="day-attendees-tab"'], false)
            ->assertDontSee('id="manual-event"', false)->assertDontSee('id="manual-day"', false)
            ->assertSee(route('events.days.manual.search', [$event, $day], absolute: false), false)
            ->assertSee(route('events.days.manual.check-in', [$event, $day], absolute: false), false);

        $this->actingAs($this->operator('monitoring'))->get(route('events.days.show', [$event, $day]))->assertOk()
            ->assertDontSee('id="day-manual-tab"', false);
    }

    public function test_event_day_manual_routes_use_the_bound_context_and_enforce_day_invitations(): void
    {
        [$event, $day] = $this->eventDay();
        $otherDay = EventDay::factory()->for($event)->create(['day_number' => 2, 'date' => '2027-01-16']);
        $employee = Employee::factory()->create(['name' => 'Context Candidate']);
        $registration = EventRegistration::factory()->for($event)->for($employee)->invitedTo($day)->create();
        $this->actingAs($this->operator());

        $search = ['participant_type' => 'employee', 'search' => 'Context', 'event_day_id' => $otherDay->id];
        $this->getJson(route('events.days.manual.search', [$event, $day, ...$search]))->assertOk()
            ->assertJsonPath('data.0.registration_id', $registration->id);
        $this->getJson(route('events.days.manual.search', [$event, $otherDay, ...$search]))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(route('events.days.manual.check-in', [$event, $otherDay]), ['registration_id' => $registration->id])
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->postJson(route('events.days.manual.check-in', [$event, $day]), ['registration_id' => $registration->id, 'event_day_id' => $otherDay->id])
            ->assertCreated();
        $this->assertDatabaseHas('attendances', ['event_registration_id' => $registration->id, 'event_day_id' => $day->id]);
        $this->postJson(route('events.days.manual.check-in', [Event::factory()->create(), $day]), ['registration_id' => $registration->id])->assertNotFound();
    }

    public function test_manual_checkins_are_idempotent_per_day_and_feed_reports_and_analytics(): void
    {
        [$event, $firstDay] = $this->eventDay();
        $secondDay = EventDay::factory()->for($event)->create(['day_number' => 2, 'date' => '2027-01-16']);
        $internal = EventRegistration::factory()->for($event)->invitedTo($firstDay, $secondDay)->create();
        $external = EventRegistration::factory()->external()->for($event)->invitedTo($firstDay, $secondDay)->create();
        $operator = $this->operator('event-admin');
        $this->actingAs($operator);

        foreach ([$internal, $external] as $registration) {
            $this->postJson(route('attendance.manual.check-in'), $this->payload($event, $firstDay, $registration))
                ->assertCreated()->assertJsonPath('created', true)->assertJsonPath('data.registration_number', $registration->registration_number)
                ->assertJsonPath('data.checkin_time', '09:30:00');
            $this->assertDatabaseHas('attendances', ['event_registration_id' => $registration->id, 'event_day_id' => $firstDay->id, 'checked_in_by' => $operator->id]);
        }
        $this->postJson(route('attendance.manual.check-in'), $this->payload($event, $firstDay, $external))->assertOk()->assertJsonPath('created', false);
        $this->postJson(route('attendance.manual.check-in'), $this->payload($event, $secondDay, $internal))->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->travelTo(Carbon::parse('2027-01-16 10:00:00', config('app.timezone')));
        $this->postJson(route('attendance.manual.check-in'), $this->payload($event, $secondDay, $internal))->assertCreated();

        $this->assertDatabaseCount('attendances', 3);
        $this->getJson(route('events.days.attendance', [$event, $firstDay]))->assertOk()
            ->assertJsonPath('summary.checked_in', 2)
            ->assertJsonFragment(['registration_number' => $internal->registration_number])
            ->assertJsonFragment(['registration_number' => $external->registration_number]);
        $this->getJson(route('events.reports.data', ['event' => $event, 'event_day' => $firstDay->id]))->assertOk()->assertJsonPath('summary.present', 2);
        $this->getJson(route('analytic.data', ['event' => $event->uuid]))->assertOk()->assertJsonPath('data.kpis.total_checkins', 3);
        $dashboard = $this->get(route('dashboard', ['event' => $event->uuid]))->assertOk();
        $this->assertSame(3, $dashboard->viewData('stats')['total_attendance']);
        $this->assertSame(75.0, $dashboard->viewData('stats')['attendance_percentage']);

        $this->postJson(route('attendance.check-in'), [
            'event_id' => $event->id, 'event_day_id' => $secondDay->id, 'qr_code' => 'RA1:'.$external->uuid,
        ])->assertCreated();
        $dashboard = $this->get(route('dashboard', ['event' => $event->uuid]))->assertOk();
        $this->assertSame(4, $dashboard->viewData('stats')['total_attendance']);
        $this->assertSame(100.0, $dashboard->viewData('stats')['attendance_percentage']);
    }

    public function test_endpoints_reject_wrong_context_ineligible_registrations_and_unauthorized_requests(): void
    {
        [$event, $day] = $this->eventDay();
        [$otherEvent, $otherDay] = $this->eventDay();
        $registration = EventRegistration::factory()->for($event)->create();
        $cancelled = EventRegistration::factory()->cancelled()->for($event)->create();
        $inactive = EventRegistration::factory()->for($event)->for(Employee::factory()->inactive())->create();
        $search = $this->searchUrl($event, $day, 'employee', 'Ayu');
        $checkIn = route('attendance.manual.check-in');
        $payload = $this->payload($event, $day, $registration);

        $this->getJson($search)->assertUnauthorized();
        $this->postJson($checkIn, $payload)->assertUnauthorized();
        $this->actingAs($this->operator('monitoring'))->getJson($search)->assertForbidden();
        $this->postJson($checkIn, $payload)->assertForbidden();

        $this->actingAs($this->operator())->getJson($this->searchUrl($event, $otherDay, 'employee', 'Ayu'))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->postJson($checkIn, $this->payload($event, $otherDay, $registration))->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->postJson($checkIn, $this->payload($otherEvent, $otherDay, $registration))->assertUnprocessable()->assertJsonValidationErrors('registration_id');
        foreach ([$cancelled, $inactive] as $ineligible) {
            $this->postJson($checkIn, $this->payload($event, $day, $ineligible))->assertUnprocessable();
        }
        $this->assertDatabaseCount('attendances', 0);
    }

    private function operator(string $role = 'panitia'): User
    {
        return User::factory()->create(['is_active' => true])->assignRole($role);
    }

    /** @return array{Event, EventDay} */
    private function eventDay(): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-16']);

        return [$event, EventDay::factory()->for($event)->create(['date' => '2027-01-15'])];
    }

    private function searchUrl(Event $event, EventDay $day, string $type, string $search): string
    {
        return route('attendance.manual.search', ['event_id' => $event->id, 'event_day_id' => $day->id, 'participant_type' => $type, 'search' => $search]);
    }

    /** @return array{event_id: int, event_day_id: int, registration_id: int} */
    private function payload(Event $event, EventDay $day, EventRegistration $registration): array
    {
        return ['event_id' => $event->id, 'event_day_id' => $day->id, 'registration_id' => $registration->id];
    }
}
