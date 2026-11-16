<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventParticipantListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('events.view', 'web');
        Permission::findOrCreate('events.update', 'web');
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function event(): Event
    {
        return Event::factory()->create([
            'status' => Event::STATUS_DRAFT,
        ]);
    }

    public function test_authorized_user_can_view_participant_list(): void
    {
        $event = $this->event();

        EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->userWithPermissions(['events.view']))->get(route('events.show', $event))->assertOk()->assertSee('Daftar Peserta')->assertSee('eventParticipantsTable');
    }

    public function test_employee_registration_is_displayed(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->employee()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->userWithPermissions(['events.view']))
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee($registration->employee->name)
            ->assertSee($registration->employee->nip)
            ->assertSee('NIP:')
            ->assertDontSee('NIK:')
            ->assertSee($registration->registration_number);
    }

    public function test_external_registration_is_displayed(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->external()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->userWithPermissions(['events.view']))->get(route('events.show', $event))->assertOk()->assertSee($registration->externalParticipant->name);
    }

    public function test_cancelled_registration_remains_visible(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->userWithPermissions(['events.view']))->get(route('events.show', $event))->assertOk()->assertSee($registration->registration_number)->assertSee('Cancelled');
    }

    public function test_user_without_update_permission_cannot_see_cancel_button(): void
    {
        $event = $this->event();

        EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->userWithPermissions(['events.view']))->get(route('events.show', $event))->assertOk()->assertDontSee('data-cancel-url');
    }

    public function test_user_with_update_permission_can_see_cancel_button(): void
    {
        $event = $this->event();

        EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->userWithPermissions(['events.view', 'events.update']))->get(route('events.show', $event))->assertOk()->assertSee('data-cancel-url');
    }

    public function test_participant_from_another_event_is_not_displayed(): void
    {
        $event = $this->event();

        $otherEvent = $this->event();

        $registration = EventRegistration::factory()->create([
            'event_id' => $otherEvent->id,
        ]);

        $this->actingAs($this->userWithPermissions(['events.view']))->get(route('events.show', $event))->assertOk()->assertDontSee($registration->registration_number);
    }

    public function test_guest_cannot_view_participant_list(): void
    {
        $event = $this->event();

        $this->get(route('events.show', $event))->assertRedirect(route('login'));
    }

    public function test_participant_summary_counts_registered_and_cancelled_participants(): void
    {
        $event = Event::factory()->create(['status' => Event::STATUS_DRAFT, 'capacity' => 4]);

        EventRegistration::factory()->create(['event_id' => $event->id]);
        EventRegistration::factory()->external()->create(['event_id' => $event->id]);
        EventRegistration::factory()->cancelled()->create(['event_id' => $event->id]);

        $this->actingAs($this->userWithPermissions(['events.view']))
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertViewHas('event', fn (Event $shownEvent): bool => $shownEvent->registered_participants_count === 2
                && $shownEvent->registered_employees_count === 1
                && $shownEvent->registered_externals_count === 1
                && $shownEvent->cancelled_registrations_count === 1)
            ->assertViewHas('remainingCapacity', 2);
    }
}
