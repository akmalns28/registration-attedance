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

class EventRegistrationReactivationTest extends TestCase
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

    private function event(?int $capacity = null, string $status = Event::STATUS_DRAFT): Event
    {
        return Event::factory()->create([
            'capacity' => $capacity,
            'status' => $status,
        ]);
    }

    private function reactivateUrl(Event $event, EventRegistration $registration): string
    {
        return route('events.participants.reactivate', [$event, $registration]);
    }

    private function redirectUrl(Event $event): string
    {
        return route('events.show', $event).'#event-participants';
    }

    public function test_can_reactivate_employee_registration(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
                'employee_id' => $employee->id,
            ]);

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertRedirect($this->redirectUrl($event));

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => EventRegistration::STATUS_REGISTERED,
            'cancelled_at' => null,
        ]);
    }

    public function test_can_reactivate_external_registration(): void
    {
        $event = $this->event();

        $external = ExternalParticipant::factory()->create();

        $registration = EventRegistration::factory()
            ->external()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
                'external_participant_id' => $external->id,
            ]);

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertRedirect($this->redirectUrl($event));

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => EventRegistration::STATUS_REGISTERED,
        ]);
    }

    public function test_reactivation_preserves_registration_identity(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $originalId = $registration->id;
        $originalUuid = $registration->uuid;
        $originalNumber = $registration->registration_number;

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertRedirect($this->redirectUrl($event));

        $registration->refresh();

        $this->assertSame($originalId, $registration->id);
        $this->assertSame($originalUuid, $registration->uuid);

        $this->assertSame($originalNumber, $registration->registration_number);
    }

    public function test_reactivation_does_not_create_duplicate(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertRedirect($this->redirectUrl($event));

        $this->assertSame(1, $event->registrations()->count());
    }

    public function test_reactivation_is_rejected_when_capacity_is_full(): void
    {
        $event = $this->event(capacity: 1);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $cancelled = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->authorizedUser())
            ->post($this->reactivateUrl($event, $cancelled))
            ->assertRedirect($this->redirectUrl($event))
            ->assertSessionHasErrors('participants');

        $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Kapasitas tidak mencukupi. Sisa kuota: 0 peserta.')
            ->assertSee('data-participant-errors="true"', false);

        $this->assertSame(EventRegistration::STATUS_CANCELLED, $cancelled->fresh()->status);
    }

    public function test_closed_event_rejects_reactivation(): void
    {
        $event = $this->event(status: Event::STATUS_CLOSED);

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->authorizedUser())
            ->post($this->reactivateUrl($event, $registration))
            ->assertRedirect($this->redirectUrl($event))
            ->assertSessionHasErrors('event');

        $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Event tidak menerima registrasi peserta.')
            ->assertSee('data-participant-errors="true"', false);

        $this->assertTrue($registration->fresh()->isCancelled());
    }

    public function test_cancelled_event_rejects_reactivation(): void
    {
        $event = $this->event(status: Event::STATUS_CANCELLED);

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertSessionHasErrors('event');

        $this->assertTrue($registration->fresh()->isCancelled());
    }

    public function test_inactive_employee_cannot_be_reactivated(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create([
            'is_active' => false,
        ]);

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
                'employee_id' => $employee->id,
            ]);

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertSessionHasErrors('participants');

        $this->assertTrue($registration->fresh()->isCancelled());
    }

    public function test_reactivation_of_registered_participant_is_idempotent(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertRedirect($this->redirectUrl($event));

        $this->assertSame(1, $event->registrations()->count());

        $this->assertTrue($registration->fresh()->isRegistered());
    }

    public function test_registration_from_another_event_cannot_be_reactivated(): void
    {
        $event = $this->event();
        $otherEvent = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $otherEvent->id,
            ]);

        $this->actingAs($this->authorizedUser())->post($this->reactivateUrl($event, $registration))->assertNotFound();

        $this->assertTrue($registration->fresh()->isCancelled());
    }

    public function test_user_without_permission_cannot_reactivate(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)->post($this->reactivateUrl($event, $registration))->assertForbidden();

        $this->assertTrue($registration->fresh()->isCancelled());
    }

    public function test_guest_cannot_reactivate_registration(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->post($this->reactivateUrl($event, $registration))->assertRedirect(route('login'));

        $this->assertTrue($registration->fresh()->isCancelled());
    }
}
