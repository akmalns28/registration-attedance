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

class EventRegistrationHardeningTest extends TestCase
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

    public function test_numeric_equivalent_ids_are_deduplicated(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create([
            'is_active' => true,
        ]);

        $result = $this->service->registerEmployees($event, [$employee->id, (string) $employee->id, str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT)]);

        $this->assertSame(1, $result['created']);

        $this->assertSame(1, $event->registrations()->count());
    }

    public function test_invalid_participant_id_is_rejected(): void
    {
        $event = $this->event();

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, ['invalid-id']);
    }

    public function test_negative_participant_id_is_rejected(): void
    {
        $event = $this->event();

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, [-1]);
    }

    public function test_registration_batch_limit_is_enforced(): void
    {
        $event = $this->event();

        $this->expectException(ValidationException::class);

        $this->service->registerEmployees($event, range(1, 501));
    }

    public function test_failed_batch_does_not_create_partial_registrations(): void
    {
        $event = $this->event();

        $employee = Employee::factory()->create([
            'is_active' => true,
        ]);

        try {
            $this->service->registerEmployees($event, [$employee->id, 999999999]);

            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(0, $event->registrations()->count());
        }
    }

    public function test_cancellation_preserves_registration_identity(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        $originalId = $registration->id;
        $originalUuid = $registration->uuid;
        $originalNumber = $registration->registration_number;

        $this->service->cancel($registration);

        $registration->refresh();

        $this->assertTrue($registration->isCancelled());

        $this->assertSame($originalId, $registration->id);

        $this->assertSame($originalUuid, $registration->uuid);

        $this->assertSame($originalNumber, $registration->registration_number);
    }

    public function test_reactivation_preserves_registration_identity(): void
    {
        $event = $this->event();

        $registration = EventRegistration::factory()
            ->cancelled()
            ->create([
                'event_id' => $event->id,
            ]);

        $originalUuid = $registration->uuid;
        $originalNumber = $registration->registration_number;

        $this->service->reactivate($registration);

        $registration->refresh();

        $this->assertTrue($registration->isRegistered());

        $this->assertSame($originalUuid, $registration->uuid);

        $this->assertSame($originalNumber, $registration->registration_number);

        $this->assertSame(1, $event->registrations()->count());
    }

    public function test_cancelled_registration_does_not_consume_capacity(): void
    {
        $event = $this->event(capacity: 1);

        $first = Employee::factory()->create();
        $second = Employee::factory()->create();

        $this->service->registerEmployees($event, [$first->id]);

        $registration = $event->registrations()->firstOrFail();

        $this->service->cancel($registration);

        $this->service->registerEmployees($event, [$second->id]);

        $this->assertSame(1, $event->registrations()->registered()->count());

        $this->assertSame(2, $event->registrations()->count());
    }

    public function test_reactivation_cannot_exceed_capacity(): void
    {
        $event = $this->event(capacity: 1);

        $first = Employee::factory()->create();
        $second = Employee::factory()->create();

        $this->service->registerEmployees($event, [$first->id]);

        $registration = $event->registrations()->firstOrFail();

        $this->service->cancel($registration);

        $this->service->registerEmployees($event, [$second->id]);

        try {
            $this->service->reactivate($registration);

            $this->fail('Expected capacity validation exception.');
        } catch (ValidationException $exception) {
            $this->assertTrue($registration->fresh()->isCancelled());

            $this->assertSame(1, $event->registrations()->registered()->count());
        }
    }

    public function test_external_registration_does_not_duplicate_on_retry(): void
    {
        $event = $this->event();

        $external = ExternalParticipant::factory()->create();

        $this->service->registerExternals($event, [$external->id]);

        $result = $this->service->registerExternals($event, [$external->id]);

        $this->assertSame(0, $result['created']);

        $this->assertSame(1, $result['skipped']);

        $this->assertSame(1, $event->registrations()->externals()->count());
    }
}
