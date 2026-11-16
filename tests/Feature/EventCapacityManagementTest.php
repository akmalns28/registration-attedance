<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventCapacityManagementTest extends TestCase
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

    private function event(int $capacity = 100): Event
    {
        return Event::factory()->create([
            'capacity' => $capacity,
            'status' => Event::STATUS_DRAFT,
        ]);
    }

    private function updatePayload(Event $event, array $overrides = []): array
    {
        return array_merge(
            [
                'title' => $event->title,
                'description' => $event->description,
                'location' => $event->location,
                'start_date' => $event->start_date->format('Y-m-d'),
                'end_date' => $event->end_date->format('Y-m-d'),
                'registration_start_at' => $event->registration_start_at?->format('Y-m-d H:i'),
                'registration_end_at' => $event->registration_end_at?->format('Y-m-d H:i'),
                'capacity' => $event->capacity,
                'status' => $event->status,
            ],
            $overrides,
        );
    }

    private function createRegistrations(Event $event, int $count, bool $cancelled = false): void
    {
        EventRegistration::factory()
            ->when($cancelled, fn ($factory) => $factory->cancelled())
            ->count($count)
            ->create([
                'event_id' => $event->id,
            ]);
    }

    public function test_capacity_can_be_increased(): void
    {
        $event = $this->event(capacity: 10);

        $this->createRegistrations($event, 5);

        $this->actingAs($this->authorizedUser())
            ->put(
                route('events.update', $event),
                $this->updatePayload($event, [
                    'capacity' => 20,
                ]),
            )
            ->assertRedirect(route('events.show', $event));

        $this->assertSame(20, $event->fresh()->capacity);
    }

    public function test_capacity_can_equal_registered_count(): void
    {
        $event = $this->event(capacity: 10);

        $this->createRegistrations($event, 5);

        $this->actingAs($this->authorizedUser())
            ->put(
                route('events.update', $event),
                $this->updatePayload($event, [
                    'capacity' => 5,
                ]),
            )
            ->assertRedirect(route('events.show', $event));

        $this->assertSame(5, $event->fresh()->capacity);
    }

    public function test_capacity_cannot_be_less_than_registered_count(): void
    {
        $event = $this->event(capacity: 10);

        $this->createRegistrations($event, 5);

        $this->actingAs($this->authorizedUser())
            ->put(
                route('events.update', $event),
                $this->updatePayload($event, [
                    'capacity' => 4,
                ]),
            )
            ->assertSessionHasErrors('capacity');

        $this->assertSame(10, $event->fresh()->capacity);
    }

    public function test_cancelled_registrations_do_not_count_toward_capacity(): void
    {
        $event = $this->event(capacity: 10);

        $this->createRegistrations($event, 3);

        $this->createRegistrations($event, 2, cancelled: true);

        $this->actingAs($this->authorizedUser())
            ->put(
                route('events.update', $event),
                $this->updatePayload($event, [
                    'capacity' => 3,
                ]),
            )
            ->assertRedirect(route('events.show', $event));

        $this->assertSame(3, $event->fresh()->capacity);
    }

    public function test_capacity_can_be_changed_to_unlimited(): void
    {
        $event = $this->event(capacity: 10);

        $this->createRegistrations($event, 5);

        $this->actingAs($this->authorizedUser())
            ->put(
                route('events.update', $event),
                $this->updatePayload($event, [
                    'capacity' => null,
                ]),
            )
            ->assertRedirect(route('events.show', $event));

        $this->assertNull($event->fresh()->capacity);
    }

    public function test_user_without_permission_cannot_change_capacity(): void
    {
        $event = $this->event(capacity: 10);

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(
                route('events.update', $event),
                $this->updatePayload($event, [
                    'capacity' => 20,
                ]),
            )
            ->assertForbidden();

        $this->assertSame(10, $event->fresh()->capacity);
    }

    public function test_capacity_validation_does_not_update_other_event_fields(): void
    {
        $event = $this->event(capacity: 10);

        $originalTitle = $event->title;

        $this->createRegistrations($event, 5);

        $this->actingAs($this->authorizedUser())
            ->put(
                route('events.update', $event),
                $this->updatePayload($event, [
                    'title' => 'Updated Event Title',
                    'capacity' => 2,
                ]),
            )
            ->assertSessionHasErrors('capacity');

        $event->refresh();

        $this->assertSame($originalTitle, $event->title);

        $this->assertSame(10, $event->capacity);
    }
}
