<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventDayManagementTest extends TestCase
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

    private function event(): Event
    {
        return Event::factory()->create([
            'start_date' => '2027-01-15',
            'end_date' => '2027-01-17',
        ]);
    }

    public function test_authorized_user_can_create_event_day(): void
    {
        $event = $this->event();

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-16',
                'title' => 'Main Event',
                'is_active' => true,
            ])
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseHas('event_days', [
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => '2027-01-16',
        ]);
    }

    public function test_event_day_date_must_be_within_event_range(): void
    {
        $event = $this->event();

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-18',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('date');
    }

    public function test_duplicate_event_day_date_is_rejected(): void
    {
        $event = $this->event();

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-15',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('date');
    }

    public function test_authorized_user_can_update_event_day(): void
    {
        $event = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        $this->actingAs($this->authorizedUser())
            ->put(route('events.days.update', [$event, $day]), [
                'date' => '2027-01-16',
                'title' => 'Updated Day',
                'is_active' => true,
            ])
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseHas('event_days', [
            'id' => $day->id,
            'day_number' => 2,
            'date' => '2027-01-16',
            'title' => 'Updated Day',
        ]);
    }

    public function test_event_day_from_another_event_cannot_be_updated(): void
    {
        $event = $this->event();
        $otherEvent = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $otherEvent->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->put(route('events.days.update', [$event, $day]), [
                'date' => '2027-01-16',
                'is_active' => true,
            ])
            ->assertNotFound();
    }

    public function test_event_day_without_schedules_can_be_deleted(): void
    {
        $event = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.days.destroy', [$event, $day]))
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseMissing('event_days', [
            'id' => $day->id,
        ]);
    }

    public function test_event_day_with_schedules_cannot_be_deleted(): void
    {
        $event = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
        ]);

        EventSchedule::factory()->create([
            'event_day_id' => $day->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.days.destroy', [$event, $day]))
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseHas('event_days', [
            'id' => $day->id,
        ]);
    }

    public function test_user_without_permission_cannot_create_event_day(): void
    {
        $event = $this->event();

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-15',
                'is_active' => true,
            ])
            ->assertForbidden();
    }
}
