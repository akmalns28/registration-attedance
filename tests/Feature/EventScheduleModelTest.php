<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventScheduleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_has_many_days(): void
    {
        $event = Event::factory()->create();

        EventDay::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->assertCount(1, $event->days);
    }

    public function test_event_day_belongs_to_event(): void
    {
        $event = Event::factory()->create();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->assertEquals($event->id, $day->event->id);
    }

    public function test_event_day_has_many_schedules(): void
    {
        $day = EventDay::factory()->create();

        EventSchedule::factory()
            ->count(3)
            ->create([
                'event_day_id' => $day->id,
            ]);

        $this->assertCount(3, $day->schedules);
    }

    public function test_schedule_belongs_to_event_day(): void
    {
        $day = EventDay::factory()->create();

        $schedule = EventSchedule::factory()->create([
            'event_day_id' => $day->id,
        ]);

        $this->assertEquals($day->id, $schedule->eventDay->id);
    }

    public function test_event_days_are_ordered_by_day_number(): void
    {
        $event = Event::factory()->create();

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => '2027-01-16',
        ]);

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        $this->assertEquals([1, 2], $event->days->pluck('day_number')->all());
    }

    public function test_schedules_are_ordered_by_start_time(): void
    {
        $day = EventDay::factory()->create();

        EventSchedule::factory()->create([
            'event_day_id' => $day->id,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
        ]);

        EventSchedule::factory()->create([
            'event_day_id' => $day->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->assertEquals(['09:00:00', '13:00:00'], $day->schedules->pluck('start_time')->all());
    }

    public function test_event_day_date_must_be_unique_per_event(): void
    {
        $event = Event::factory()->create();

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        $this->expectException(QueryException::class);

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => '2027-01-15',
        ]);
    }

    public function test_event_day_number_must_be_unique_per_event(): void
    {
        $event = Event::factory()->create();

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        $this->expectException(QueryException::class);

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-16',
        ]);
    }

    public function test_event_can_load_days_and_schedules(): void
    {
        $event = Event::factory()->create();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
        ]);

        EventSchedule::factory()->create([
            'event_day_id' => $day->id,
        ]);

        $event = Event::with('days.schedules')->findOrFail($event->id);

        $this->assertCount(1, $event->days);

        $this->assertCount(1, $event->days->first()->schedules);
    }
}
