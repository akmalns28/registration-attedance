<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventRegistrationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_employee_registration(): void
    {
        $registration = EventRegistration::factory()->employee()->create();

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'registration_type' => 'employee',
        ]);

        $this->assertNotNull($registration->employee_id);

        $this->assertNull($registration->external_participant_id);
    }

    public function test_can_create_external_registration(): void
    {
        $registration = EventRegistration::factory()->external()->create();

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'registration_type' => 'external',
        ]);

        $this->assertNull($registration->employee_id);

        $this->assertNotNull($registration->external_participant_id);
    }

    public function test_registration_generates_uuid_v7(): void
    {
        $registration = EventRegistration::factory()->create();

        $this->assertNotEmpty($registration->uuid);

        $this->assertEquals(7, Str::of($registration->uuid)->explode('-')[2][0]);
    }

    public function test_registration_generates_unique_registration_number(): void
    {
        $first = EventRegistration::factory()->create();
        $second = EventRegistration::factory()->create();

        $this->assertNotEmpty($first->registration_number);

        $this->assertNotEquals($first->registration_number, $second->registration_number);
    }

    public function test_registration_belongs_to_event(): void
    {
        $event = Event::factory()->create();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->assertEquals($event->id, $registration->event->id);
    }

    public function test_event_has_many_registrations(): void
    {
        $event = Event::factory()->create();

        EventRegistration::factory()
            ->count(3)
            ->create([
                'event_id' => $event->id,
            ]);

        $this->assertCount(3, $event->registrations);
    }

    public function test_employee_registration_has_employee_relationship(): void
    {
        $employee = Employee::factory()->create();

        $registration = EventRegistration::factory()->create([
            'employee_id' => $employee->id,
            'external_participant_id' => null,
            'registration_type' => 'employee',
        ]);

        $this->assertEquals($employee->id, $registration->employee->id);
    }

    public function test_external_registration_has_external_relationship(): void
    {
        $external = ExternalParticipant::factory()->create();

        $registration = EventRegistration::factory()
            ->external()
            ->create([
                'external_participant_id' => $external->id,
            ]);

        $this->assertEquals($external->id, $registration->externalParticipant->id);
    }

    public function test_registered_scope_returns_only_registered_participants(): void
    {
        EventRegistration::factory()->create();

        EventRegistration::factory()->cancelled()->create();

        $this->assertEquals(1, EventRegistration::registered()->count());
    }

    public function test_cancelled_scope_returns_only_cancelled_participants(): void
    {
        EventRegistration::factory()->create();

        EventRegistration::factory()->cancelled()->create();

        $this->assertEquals(1, EventRegistration::cancelled()->count());
    }

    public function test_registration_can_get_participant_name(): void
    {
        $employee = Employee::factory()->create([
            'name' => 'Budi Santoso',
        ]);

        $registration = EventRegistration::factory()->create([
            'employee_id' => $employee->id,
        ]);

        $this->assertEquals('Budi Santoso', $registration->participant_name);
    }

    public function test_employee_cannot_register_twice_in_same_event(): void
    {
        $event = Event::factory()->create();
        $employee = Employee::factory()->create();

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'employee_id' => $employee->id,
        ]);

        $this->expectException(QueryException::class);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_external_cannot_register_twice_in_same_event(): void
    {
        $event = Event::factory()->create();

        $external = ExternalParticipant::factory()->create();

        EventRegistration::factory()
            ->external()
            ->create([
                'event_id' => $event->id,
                'external_participant_id' => $external->id,
            ]);

        $this->expectException(QueryException::class);

        EventRegistration::factory()
            ->external()
            ->create([
                'event_id' => $event->id,
                'external_participant_id' => $external->id,
            ]);
    }

    public function test_same_employee_can_register_in_different_events(): void
    {
        $employee = Employee::factory()->create();

        $firstEvent = Event::factory()->create();
        $secondEvent = Event::factory()->create();

        EventRegistration::factory()->create([
            'event_id' => $firstEvent->id,
            'employee_id' => $employee->id,
        ]);

        EventRegistration::factory()->create([
            'event_id' => $secondEvent->id,
            'employee_id' => $employee->id,
        ]);

        $this->assertEquals(2, EventRegistration::where('employee_id', $employee->id)->count());
    }

    public function test_registration_uses_uuid_for_route_binding(): void
    {
        $registration = EventRegistration::factory()->create();

        $this->assertEquals('uuid', $registration->getRouteKeyName());

        $this->assertEquals($registration->uuid, $registration->getRouteKey());
    }
}
