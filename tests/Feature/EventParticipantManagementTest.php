<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventParticipantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('events.view', 'web');

        Permission::findOrCreate('events.update', 'web');
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->givePermissionTo(['events.view', 'events.update']);

        return $user;
    }

    private function event(?int $capacity = null): Event
    {
        return Event::factory()->create([
            'status' => Event::STATUS_DRAFT,
            'capacity' => $capacity,
        ]);
    }

    private function participantUrl(Event $event): string
    {
        return route('events.participants.store', $event);
    }

    private function redirectUrl(Event $event): string
    {
        return route('events.show', $event).'#event-participants';
    }

    public function test_event_employee_selector_displays_nip_with_leading_zeroes(): void
    {
        $this->withoutVite();

        $event = $this->event();

        $employee = Employee::factory()->create([
            'nip' => '001234567890123',
        ]);

        $this->actingAs($this->authorizedUser())
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee($employee->name)
            ->assertSee('001234567890123')
            ->assertSee('Cari nama atau NIP Employee...');
    }

    public function test_authorized_user_can_register_employees(): void
    {
        $event = $this->event();

        $employees = Employee::factory()->count(3)->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => $employees->modelKeys(),
            ])
            ->assertRedirect($this->redirectUrl($event))
            ->assertSessionHas('success');

        $this->assertSame(3, $event->registrations()->employees()->count());
    }

    public function test_registration_cancellation_and_reactivation_refresh_the_participant_summary(): void
    {
        $this->withoutVite();

        $event = $this->event(capacity: 1);
        $employee = Employee::factory()->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->redirectUrl($event));

        $registration = $event->registrations()->sole();
        $originalUuid = $registration->uuid;
        $originalNumber = $registration->registration_number;

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertViewHas('event', fn (Event $shownEvent): bool => $shownEvent->registered_participants_count === 1
                && $shownEvent->cancelled_registrations_count === 0)
            ->assertViewHas('remainingCapacity', 0)
            ->assertSee('data-cancel-url="'.route('events.participants.destroy', [$event, $registration]).'"', false);

        $this->delete(route('events.participants.destroy', [$event, $registration]))
            ->assertRedirect($this->redirectUrl($event));

        $this->assertTrue($registration->fresh()->isCancelled());

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertViewHas('event', fn (Event $shownEvent): bool => $shownEvent->registered_participants_count === 0
                && $shownEvent->cancelled_registrations_count === 1)
            ->assertViewHas('remainingCapacity', 1)
            ->assertSee('data-reactivate-url="'.route('events.participants.reactivate', [$event, $registration]).'"', false);

        $this->post(route('events.participants.reactivate', [$event, $registration]))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->redirectUrl($event));

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertViewHas('event', fn (Event $shownEvent): bool => $shownEvent->registered_participants_count === 1
                && $shownEvent->cancelled_registrations_count === 0)
            ->assertViewHas('remainingCapacity', 0);

        $this->assertSame(1, $event->registrations()->count());
        $this->assertSame($originalUuid, $registration->fresh()->uuid);
        $this->assertSame($originalNumber, $registration->fresh()->registration_number);
    }

    public function test_authorized_user_can_register_external_participants(): void
    {
        $event = $this->event();

        $participants = ExternalParticipant::factory()->count(2)->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'external',
                'participant_ids' => $participants->modelKeys(),
            ])
            ->assertRedirect($this->redirectUrl($event));

        $this->assertSame(2, $event->registrations()->externals()->count());
    }

    public function test_participant_type_is_required(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_ids' => [$employee->id],
            ])
            ->assertSessionHasErrors('participant_type');
    }

    public function test_invalid_participant_type_is_rejected(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'invalid',
                'participant_ids' => [$employee->id],
            ])
            ->assertSessionHasErrors('participant_type');
    }

    public function test_participant_list_is_required(): void
    {
        $event = $this->event();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [],
            ])
            ->assertSessionHasErrors('participant_ids');
    }

    public function test_duplicate_ids_in_request_are_rejected(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id, $employee->id],
            ])
            ->assertSessionHasErrors('participant_ids.0');

        $this->assertSame(0, $event->registrations()->count());
    }

    public function test_existing_registration_is_skipped(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id],
            ])
            ->assertRedirect($this->redirectUrl($event));

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id],
            ])
            ->assertRedirect($this->redirectUrl($event));

        $this->assertSame(1, $event->registrations()->count());
    }

    public function test_capacity_validation_prevents_registration(): void
    {
        $event = $this->event(capacity: 1);

        $employees = Employee::factory()->count(2)->create();

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => $employees->modelKeys(),
            ])
            ->assertSessionHasErrors('participants');

        $this->assertSame(0, $event->registrations()->count());
    }

    public function test_inactive_employee_cannot_register(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create([
            'is_active' => false,
        ]);

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id],
            ])
            ->assertSessionHasErrors('participants');
    }

    public function test_authorized_user_can_cancel_registration(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->authorizedUser())->delete(route('events.participants.destroy', [$event, $registration]))->assertRedirect($this->redirectUrl($event));

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => EventRegistration::STATUS_CANCELLED,
        ]);
    }

    public function test_cancelled_registration_can_be_reactivated(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
                'employee_id' => $employee->id,
            ]);

        $this->actingAs($this->authorizedUser())
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id],
            ])
            ->assertRedirect($this->redirectUrl($event));

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => EventRegistration::STATUS_REGISTERED,
            'cancelled_at' => null,
        ]);
    }

    public function test_registration_from_another_event_cannot_be_cancelled(): void
    {
        $event = $this->event();

        $otherEvent = $this->event();

        $registration = EventRegistration::factory()->create([
            'event_id' => $otherEvent->id,
        ]);

        $this->actingAs($this->authorizedUser())->delete(route('events.participants.destroy', [$event, $registration]))->assertNotFound();

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => EventRegistration::STATUS_REGISTERED,
        ]);
    }

    public function test_user_without_permission_cannot_register(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post($this->participantUrl($event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id],
            ])
            ->assertForbidden();
    }

    public function test_user_without_permission_cannot_cancel_registration(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)->delete(route('events.participants.destroy', [$event, $registration]))->assertForbidden();
    }

    public function test_guest_cannot_register_participants(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->post($this->participantUrl($event), [
            'participant_type' => 'employee',
            'participant_ids' => [$employee->id],
        ])->assertRedirect(route('login'));

        $this->assertSame(0, $event->registrations()->count());
    }
}
