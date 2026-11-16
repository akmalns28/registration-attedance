<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_can_be_created(): void
    {
        $event = Event::factory()->create([
            'title' => 'Annual Gathering 2027',
        ]);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'title' => 'Annual Gathering 2027',
        ]);
    }

    public function test_event_generates_uuid_automatically(): void
    {
        $event = Event::factory()->create();

        $this->assertNotNull($event->uuid);

        $this->assertTrue(Str::isUuid($event->uuid));

        $this->assertEquals(7, Str::of($event->uuid)->charAt(14));
    }

    public function test_event_uses_uuid_for_route_binding(): void
    {
        $event = Event::factory()->create();

        $this->assertEquals('uuid', $event->getRouteKeyName());

        $this->assertEquals($event->uuid, $event->getRouteKey());
    }

    public function test_event_has_draft_status_by_default(): void
    {
        $event = Event::create([
            'title' => 'Test Event',
            'start_date' => '2027-01-15',
            'end_date' => '2027-01-15',
        ]);

        $this->assertEquals(Event::STATUS_DRAFT, $event->fresh()->status);
    }

    public function test_published_scope_only_returns_published_events(): void
    {
        Event::factory()->create();

        Event::factory()->published()->create();

        $this->assertEquals(1, Event::published()->count());
    }

    public function test_event_can_detect_multi_day(): void
    {
        $event = Event::factory()->create([
            'start_date' => '2027-01-15',
            'end_date' => '2027-01-17',
        ]);

        $this->assertTrue($event->isMultiDay());
    }

    public function test_single_day_event_is_not_multi_day(): void
    {
        $event = Event::factory()->create([
            'start_date' => '2027-01-15',
            'end_date' => '2027-01-15',
        ]);

        $this->assertFalse($event->isMultiDay());
    }

    public function test_event_uuid_is_unique(): void
    {
        $event1 = Event::factory()->create();

        $event2 = Event::factory()->create();

        $this->assertNotEquals($event1->uuid, $event2->uuid);
    }
}
