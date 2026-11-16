<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EventRegistrationNotificationModelTest extends TestCase
{
    use RefreshDatabase;

    private function registration(): EventRegistration
    {
        return EventRegistration::factory()->create();
    }

    private function notification(EventRegistration $registration, array $attributes = []): EventRegistrationNotification
    {
        return EventRegistrationNotification::factory()->create(
            array_merge(
                [
                    'event_id' => $registration->event_id,
                    'event_registration_id' => $registration->id,
                ],
                $attributes,
            ),
        );
    }

    public function test_notification_can_be_created(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration);

        $this->assertDatabaseHas('event_registration_notifications', [
            'id' => $notification->id,
            'event_id' => $registration->event_id,
            'event_registration_id' => $registration->id,
            'status' => EventRegistrationNotification::STATUS_PENDING,
        ]);
    }

    public function test_default_notification_status_is_pending(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration);

        $this->assertTrue($notification->isPending());

        $this->assertSame(0, $notification->attempts);
    }

    public function test_notification_belongs_to_event(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration);

        $this->assertInstanceOf(Event::class, $notification->event);

        $this->assertSame($registration->event_id, $notification->event->id);
    }

    public function test_notification_belongs_to_registration(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration);

        $this->assertInstanceOf(EventRegistration::class, $notification->registration);

        $this->assertSame($registration->id, $notification->registration->id);
    }

    public function test_registration_has_many_notifications(): void
    {
        $registration = $this->registration();

        $this->notification($registration, [
            'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
        ]);

        $this->notification($registration, [
            'notification_type' => EventRegistrationNotification::TYPE_REMINDER,
        ]);

        $this->assertSame(2, $registration->notifications()->count());
    }

    public function test_event_has_many_registration_notifications(): void
    {
        $registration = $this->registration();

        $this->notification($registration);

        $this->assertSame(1, $registration->event->registrationNotifications()->count());
    }

    public function test_duplicate_dedupe_key_is_rejected(): void
    {
        $registration = $this->registration();

        $this->notification($registration, [
            'dedupe_key' => 'registration-test-invitation-1',
        ]);

        $this->expectException(QueryException::class);

        $this->notification($registration, [
            'dedupe_key' => 'registration-test-invitation-1',
        ]);
    }

    public function test_different_notification_cycles_are_allowed(): void
    {
        $registration = $this->registration();

        $this->notification($registration, [
            'notification_type' => EventRegistrationNotification::TYPE_CANCELLATION,

            'dedupe_key' => 'registration-test-cancellation-1',
        ]);

        $this->notification($registration, [
            'notification_type' => EventRegistrationNotification::TYPE_CANCELLATION,

            'dedupe_key' => 'registration-test-cancellation-2',
        ]);

        $this->assertSame(2, $registration->notifications()->ofType(EventRegistrationNotification::TYPE_CANCELLATION)->count());
    }

    public function test_notification_status_scopes_work(): void
    {
        $registration = $this->registration();

        $this->notification($registration, [
            'status' => EventRegistrationNotification::STATUS_PENDING,
        ]);

        $this->notification($registration, [
            'status' => EventRegistrationNotification::STATUS_SENT,
        ]);

        $this->notification($registration, [
            'status' => EventRegistrationNotification::STATUS_FAILED,
        ]);

        $this->assertSame(1, $registration->notifications()->pending()->count());

        $this->assertSame(1, $registration->notifications()->sent()->count());

        $this->assertSame(1, $registration->notifications()->failed()->count());
    }

    public function test_notification_type_scope_works(): void
    {
        $registration = $this->registration();

        $this->notification($registration, [
            'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
        ]);

        $this->notification($registration, [
            'notification_type' => EventRegistrationNotification::TYPE_REMINDER,
        ]);

        $this->assertSame(1, $registration->notifications()->ofType(EventRegistrationNotification::TYPE_REMINDER)->count());
    }

    public function test_metadata_is_cast_to_array(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration, [
            'metadata' => [
                'source' => 'event_published',
                'batch' => 1,
            ],
        ]);

        $notification->refresh();

        $this->assertIsArray($notification->metadata);

        $this->assertSame('event_published', $notification->metadata['source']);

        $this->assertSame(1, $notification->metadata['batch']);
    }

    public function test_sent_at_is_cast_to_datetime(): void
    {
        $registration = $this->registration();

        $notification = EventRegistrationNotification::factory()
            ->sent()
            ->create([
                'event_id' => $registration->event_id,
                'event_registration_id' => $registration->id,
            ]);

        $this->assertTrue($notification->isSent());

        $this->assertInstanceOf(Carbon::class, $notification->sent_at);
    }

    public function test_failed_notification_stores_error(): void
    {
        $registration = $this->registration();

        $notification = EventRegistrationNotification::factory()
            ->failed()
            ->create([
                'event_id' => $registration->event_id,
                'event_registration_id' => $registration->id,
            ]);

        $this->assertTrue($notification->isFailed());

        $this->assertNotNull($notification->last_error);

        $this->assertSame(1, $notification->attempts);
    }

    public function test_notification_without_email_can_be_skipped(): void
    {
        $registration = $this->registration();

        $notification = EventRegistrationNotification::factory()
            ->skipped()
            ->create([
                'event_id' => $registration->event_id,
                'event_registration_id' => $registration->id,
            ]);

        $this->assertTrue($notification->isSkipped());

        $this->assertNull($notification->recipient_email);
    }

    public function test_notification_types_are_defined(): void
    {
        $this->assertSame(['invitation', 'cancellation', 'reactivation', 'reminder'], EventRegistrationNotification::types());
    }
}
