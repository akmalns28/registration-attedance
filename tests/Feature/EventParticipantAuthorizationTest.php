<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventParticipantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('events.view', 'web');
        Permission::findOrCreate('events.update', 'web');
    }

    private function userWithPermissions(array $permissions = []): User
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

    private function registration(Event $event): EventRegistration
    {
        return EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);
    }

    public function test_view_permission_can_access_event_participants(): void
    {
        $event = $this->event();

        $this->actingAs($this->userWithPermissions(['events.view']))->get(route('events.show', $event))->assertOk()->assertSee('Event Participants');
    }

    public function test_view_only_user_cannot_see_registration_actions(): void
    {
        $event = $this->event();

        $this->registration($event);

        $this->actingAs($this->userWithPermissions(['events.view']))->get(route('events.show', $event))->assertOk()->assertDontSee('data-cancel-url')->assertDontSee('data-reactivate-url')->assertDontSee('id="addParticipantsModal"', false);
    }

    public function test_update_permission_can_see_registration_actions(): void
    {
        $event = $this->event();

        $this->registration($event);

        $this->actingAs($this->userWithPermissions(['events.view', 'events.update']))->get(route('events.show', $event))->assertOk()->assertSee('data-cancel-url')->assertSee('id="addParticipantsModal"', false);
    }

    public function test_user_without_view_permission_cannot_access_event(): void
    {
        $event = $this->event();

        $this->actingAs($this->userWithPermissions())->get(route('events.show', $event))->assertForbidden();
    }

    public function test_view_only_user_cannot_register_participants(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->actingAs($this->userWithPermissions(['events.view']))
            ->post(route('events.participants.store', $event), [
                'participant_type' => 'employee',
                'participant_ids' => [$employee->id],
            ])
            ->assertForbidden();

        $this->assertSame(0, $event->registrations()->count());
    }

    public function test_view_only_user_cannot_cancel_registration(): void
    {
        $event = $this->event();

        $registration = $this->registration($event);

        $this->actingAs($this->userWithPermissions(['events.view']))->delete(route('events.participants.destroy', [$event, $registration]))->assertForbidden();

        $this->assertTrue($registration->fresh()->isRegistered());
    }

    public function test_view_only_user_cannot_reactivate_registration(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $this->actingAs($this->userWithPermissions(['events.view']))->post(route('events.participants.reactivate', [$event, $registration]))->assertForbidden();

        $this->assertTrue($registration->fresh()->isCancelled());
    }

    public function test_guest_cannot_access_participant_management(): void
    {
        $event = $this->event();

        $this->get(route('events.show', $event))->assertRedirect(route('login'));

        $this->post(route('events.participants.store', $event))->assertRedirect(route('login'));
    }
}
