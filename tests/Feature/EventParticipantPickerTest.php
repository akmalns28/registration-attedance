<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EventParticipantPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create(['is_active' => true])->assignRole('event-admin'));
    }

    public function test_candidates_can_be_filtered_and_paginated_and_return_selectable_ids_across_pages(): void
    {
        $event = Event::factory()->create(['status' => Event::STATUS_DRAFT]);
        $department = Department::factory()->create(['name' => 'Operations']);
        $registered = Employee::factory()->for($department)->create(['name' => 'Ayu Candidate', 'nip' => '000425']);
        EventRegistration::factory()->for($event)->for($registered)->create();
        $cancelled = Employee::factory()->for($department)->create(['name' => 'Budi Candidate']);
        EventRegistration::factory()->for($event)->for($cancelled)->cancelled()->create();
        $others = Employee::factory()->count(15)->for($department)->create(['name' => 'Other Candidate']);
        Employee::factory()->create(['name' => 'Another Department']);
        Employee::factory()->for($department)->create(['is_active' => false]);
        $external = ExternalParticipant::factory()->create(['phone' => '08127000', 'organization' => 'Partner']);
        $url = fn (array $query): string => route('events.participants.candidates', [$event, ...$query]);

        $page = $this->getJson($url(['type' => 'employee', 'department_id' => $department->id, 'page' => 2]))
            ->assertOk()->assertJsonPath('total', 17)->assertJsonPath('last_page', 2)->assertJsonPath('page', 2)
            ->assertJsonCount(2, 'data')->assertJsonCount(16, 'selectable_ids')
            ->assertJsonPath('data.0.affiliation', 'Operations');
        $this->assertEqualsCanonicalizing([$cancelled->id, ...$others->modelKeys()], $page->json('selectable_ids'));
        $this->getJson($url(['type' => 'employee', 'search' => '000425']))->assertOk()
            ->assertJsonPath('data.0.status', 'registered')->assertJsonPath('selectable_ids', []);
        $this->getJson($url(['type' => 'employee', 'search' => 'Budi']))->assertOk()
            ->assertJsonPath('data.0.status', 'cancelled')->assertJsonPath('selectable_ids', [$cancelled->id]);
        $this->getJson($url(['type' => 'external', 'search' => '08127']))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $external->id)->assertJsonPath('data.0.affiliation', 'Partner');
        $this->assertDatabaseCount('event_registrations', 2);

        $this->actingAs(User::factory()->create(['is_active' => true])->assignRole('monitoring'))->getJson($url(['type' => 'employee']))->assertForbidden();
    }

    #[TestWith(['all', 2])]
    #[TestWith(['selected', 1])]
    public function test_mixed_registration_has_one_capacity_check_outcome_and_assigns_requested_days(string $coverage, int $daysPerParticipant): void
    {
        $event = Event::factory()->create(['status' => Event::STATUS_DRAFT, 'capacity' => 1, 'start_date' => '2027-01-15', 'end_date' => '2027-01-16']);
        $first = EventDay::factory()->for($event)->create(['date' => '2027-01-15', 'day_number' => 1]);
        $second = EventDay::factory()->for($event)->create(['date' => '2027-01-16', 'day_number' => 2]);
        $employee = Employee::factory()->create();
        $external = ExternalParticipant::factory()->create();
        $data = ['participant_groups' => ['employee' => [$employee->id], 'external' => [$external->id]],
            'day_selection' => $coverage, ...($coverage === 'selected' ? ['day_ids' => [$second->id]] : [])];
        $url = route('events.participants.store', $event);

        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('participants');
        $this->assertDatabaseCount('event_registrations', 0);
        $this->assertDatabaseCount('event_day_invitations', 0);

        $event->update(['capacity' => 2]);
        $this->post($url, $data)->assertRedirect(route('events.show', $event).'#event-participants');
        $this->assertDatabaseCount('event_registrations', 2);
        $this->assertDatabaseCount('event_day_invitations', $daysPerParticipant * 2);
        foreach ($event->registrations as $registration) {
            $this->assertEqualsCanonicalizing($coverage === 'all' ? [$first->id, $second->id] : [$second->id], $registration->invitedDays->modelKeys());
        }

        $this->post($url, $data)->assertSessionHas('success', 'Registrasi selesai. 0 peserta baru, 0 diaktifkan kembali, 2 sudah terdaftar.');
        $this->assertDatabaseCount('event_registrations', 2);
    }
}
