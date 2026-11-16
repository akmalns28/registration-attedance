<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Services\EventNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EventNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EventNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->service = app(EventNotificationService::class);
    }

    private function registration(string $status = Event::STATUS_PUBLISHED): EventRegistration
    {
        $event = Event::factory()->create([
            'status' => $status,
        ]);

        return EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);
    }

    public function test_notification_is_created(): void
    {
        $registration = $this->registration();

        $notification = $this->service->schedule($registration, EventRegistrationNotification::TYPE_INVITATION, 'test-invitation-001');

        $this->assertDatabaseHas('event_registration_notifications', [
            'id' => $notification->id,
            'event_registration_id' => $registration->id,
            'notification_type' => 'invitation',
        ]);
    }

    public function test_duplicate_dedupe_key_does_not_create_another_record(): void
    {
        $registration = $this->registration();

        $this->service->schedule($registration, EventRegistrationNotification::TYPE_INVITATION, 'test-invitation-002');

        $this->service->schedule($registration, EventRegistrationNotification::TYPE_INVITATION, 'test-invitation-002');

        $this->assertSame(1, $registration->notifications()->count());
    }

    public function test_draft_event_cannot_schedule_invitation(): void
    {
        $registration = $this->registration(Event::STATUS_DRAFT);

        $this->expectException(ValidationException::class);

        $this->service->schedule($registration, EventRegistrationNotification::TYPE_INVITATION, 'test-draft-invitation');
    }

    public function test_invalid_type_is_rejected(): void
    {
        $registration = $this->registration();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->schedule($registration, 'invalid-type', 'test-invalid-type');
    }

    public function test_notification_event_matches_registration(): void
    {
        $registration = $this->registration();

        $notification = $this->service->schedule($registration, EventRegistrationNotification::TYPE_INVITATION, 'test-invitation-003');

        $this->assertSame($registration->event_id, $notification->event_id);
    }

    public function test_dedupe_key_cannot_be_reused_for_another_registration(): void
    {
        $first = $this->registration();
        $second = $this->registration();

        $this->service->schedule($first, EventRegistrationNotification::TYPE_INVITATION, 'shared-key');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->schedule($second, EventRegistrationNotification::TYPE_INVITATION, 'shared-key');
    }
}
