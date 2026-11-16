<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use App\Services\EventRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EventRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EventRegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EventRegistrationService::class);
    }

    private function event(?int $capacity = null, string $status = Event::STATUS_DRAFT): Event
    {
        return Event::factory()->create([
            'capacity' => $capacity,
            'status' => $status,
        ]);
    }

    public function test_can_register_multiple_employees(): void
    {
        $event = $this->event();

        $employees = Employee::factory()->count(3)->create();

        $result = $this->service->registerEmployees($event, $employees->modelKeys());

        $this->assertSame(3, $result['created']);

        $this->assertSame(3, $event->registrations()->registered()->count());
    }

    public function test_can_register_multiple_external_participants(): void
    {
        $event = $this->event();

        $participants = ExternalParticipant::factory()->count(3)->create();

        $result = $this->service->registerExternals($event, $participants->modelKeys());

        $this->assertSame(3, $result['created']);

        $this->assertSame(3, $event->registrations()->externals()->count());
    }

    public function test_duplicate_registration_is_skipped(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->service->registerEmployees($event, [$employee->id]);

        $result = $this->service->registerEmployees($event, [$employee->id]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);

        $this->assertSame(1, $event->registrations()->count());
    }

    public function test_duplicate_ids_in_same_batch_are_ignored(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $result = $this->service->registerEmployees($event, [$employee->id, $employee->id, $employee->id]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['total']);
    }

    public function test_cancelled_registration_can_be_reactivated(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->service->registerEmployees($event, [$employee->id]);

        $registration = $event->registrations()->firstOrFail();

        $this->service->cancel($registration);

        $result = $this->service->registerEmployees($event, [$employee->id]);

        $this->assertSame(1, $result['reactivated']);

        $this->assertSame(1, $event->registrations()->count());

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => EventRegistration::STATUS_REGISTERED,
            'cancelled_at' => null,
        ]);
    }

    public function test_capacity_cannot_be_exceeded(): void
    {
        $event = $this->event(capacity: 2);

        $employees = Employee::factory()->count(3)->create();

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, $employees->modelKeys());
    }

    public function test_capacity_failure_rolls_back_entire_batch(): void
    {
        $event = $this->event(capacity: 2);

        $employees = Employee::factory()->count(3)->create();

        try {
            $this->service->registerEmployees($event, $employees->modelKeys());
        } catch (ValidationException $exception) {
            $this->assertSame(0, $event->registrations()->count());

            return;
        }

        $this->fail('Expected ValidationException was not thrown.');
    }

    public function test_cancelled_registration_releases_capacity(): void
    {
        $event = $this->event(capacity: 1);

        $firstEmployee = Employee::factory()->create();
        $secondEmployee = Employee::factory()->create();

        $this->service->registerEmployees($event, [$firstEmployee->id]);

        $registration = $event->registrations()->firstOrFail();

        $this->service->cancel($registration);

        $result = $this->service->registerEmployees($event, [$secondEmployee->id]);

        $this->assertSame(1, $result['created']);

        $this->assertSame(1, $event->registrations()->registered()->count());
    }

    public function test_inactive_employee_cannot_register(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create([
            'is_active' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, [$employee->id]);
    }

    public function test_inactive_external_cannot_register(): void
    {
        $event = $this->event();

        $external = ExternalParticipant::factory()->create([
            'is_active' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->registerExternals($event, [$external->id]);
    }

    public function test_closed_event_rejects_registration(): void
    {
        $event = $this->event(status: Event::STATUS_CLOSED);

        $employee = Employee::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, [$employee->id]);
    }

    public function test_cancelled_event_rejects_registration(): void
    {
        $event = $this->event(status: Event::STATUS_CANCELLED);

        $employee = Employee::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, [$employee->id]);
    }

    public function test_published_event_accepts_registration(): void
    {
        $event = $this->event(status: Event::STATUS_PUBLISHED);

        $employee = Employee::factory()->create();

        $result = $this->service->registerEmployees($event, [$employee->id]);

        $this->assertSame(1, $result['created']);
    }

    public function test_empty_participant_list_is_rejected(): void
    {
        $event = $this->event();

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, []);
    }

    public function test_cancel_is_idempotent(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create();

        $this->service->registerEmployees($event, [$employee->id]);

        $registration = $event->registrations()->firstOrFail();

        $first = $this->service->cancel($registration);

        $cancelledAt = $first->cancelled_at?->toDateTimeString();

        $second = $this->service->cancel($registration);

        $this->assertSame(EventRegistration::STATUS_CANCELLED, $second->status);

        $this->assertSame($cancelledAt, $second->cancelled_at?->toDateTimeString());
    }
}
