<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\EventRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventRegistrationStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function registration(string $eventStatus = Event::STATUS_PUBLISHED): EventRegistration
    {
        $event = Event::factory()->create([
            'status' => $eventStatus,
        ]);

        return EventRegistration::factory()->create([
            'event_id' => $event->id,
            'status' => EventRegistration::STATUS_REGISTERED,
        ]);
    }

    public function test_cancellation_creates_notification(): void
    {
        $registration = $this->registration();

        app(EventRegistrationService::class)->cancel($registration);

        $this->assertDatabaseHas('event_registration_notifications', [
            'event_registration_id' => $registration->id,
            'notification_type' => 'cancellation',
        ]);
    }

    public function test_repeated_cancellation_does_not_duplicate_notification(): void
    {
        $registration = $this->registration();

        $service = app(EventRegistrationService::class);

        $service->cancel($registration);
        $service->cancel($registration);

        $this->assertSame(1, $registration->notifications()->where('notification_type', 'cancellation')->count());
    }

    public function test_draft_event_cancellation_does_not_schedule_email(): void
    {
        $registration = $this->registration(Event::STATUS_DRAFT);

        app(EventRegistrationService::class)->cancel($registration);

        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_reactivation_creates_notification(): void
    {
        $registration = $this->registration();

        $service = app(EventRegistrationService::class);

        $service->cancel($registration);
        $service->reactivate($registration->fresh());

        $this->assertDatabaseHas('event_registration_notifications', [
            'event_registration_id' => $registration->id,
            'notification_type' => 'reactivation',
        ]);
    }

    public function test_multiple_status_cycles_create_distinct_notifications(): void
    {
        $registration = $this->registration();

        $service = app(EventRegistrationService::class);

        $service->cancel($registration);
        $service->reactivate($registration->fresh());

        $service->cancel($registration->fresh());
        $service->reactivate($registration->fresh());

        $this->assertSame(2, $registration->notifications()->where('notification_type', 'cancellation')->count());

        $this->assertSame(2, $registration->notifications()->where('notification_type', 'reactivation')->count());
    }

    public function test_reactivation_preserves_registration_uuid(): void
    {
        $registration = $this->registration();

        $uuid = $registration->uuid;

        $service = app(EventRegistrationService::class);

        $service->cancel($registration);
        $service->reactivate($registration->fresh());

        $this->assertSame($uuid, $registration->fresh()->uuid);
    }
}
