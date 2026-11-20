<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\User;
use App\Services\EventPublicationNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EventPublicationNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function updateEvent(Event $event, array $overrides = []): TestResponse
    {
        Permission::findOrCreate('events.update', 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('events.update');

        return $this->actingAs($user)->put(route('events.update', $event), array_replace([
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'start_date' => $event->start_date->format('Y-m-d'),
            'end_date' => $event->end_date->format('Y-m-d'),
            'registration_start_at' => null,
            'registration_end_at' => null,
            'capacity' => $event->capacity,
            'status' => Event::STATUS_PUBLISHED,
        ], $overrides));
    }

    public function test_draft_publication_schedules_active_participants_and_marks_missing_email_skipped(): void
    {
        $event = Event::factory()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        $active = EventRegistration::factory()->for($event)->invitedTo()->create(['employee_id' => $employee->id]);
        $missingEmail = Employee::factory()->create(['email' => null]);
        $skipped = EventRegistration::factory()->for($event)->invitedTo()->create(['employee_id' => $missingEmail->id]);
        $cancelled = EventRegistration::factory()->for($event)->cancelled()->create();
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        $this->updateEvent($event)->assertRedirect(route('events.show', $event));

        $this->assertSame(Event::STATUS_PUBLISHED, $event->fresh()->status);
        $this->assertDatabaseHas('event_registration_notifications', ['event_registration_id' => $active->id, 'status' => EventRegistrationNotification::STATUS_QUEUED]);
        $this->assertDatabaseHas('event_registration_notifications', ['event_registration_id' => $skipped->id, 'status' => EventRegistrationNotification::STATUS_SKIPPED]);
        $this->assertDatabaseMissing('event_registration_notifications', ['event_registration_id' => $cancelled->id]);
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    public function test_repeated_and_stale_publication_requests_do_not_repeat_invitations(): void
    {
        $event = Event::factory()->create();
        $staleEvent = $event->fresh();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        EventRegistration::factory()->for($event)->invitedTo()->create(['employee_id' => $employee->id]);
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        $this->updateEvent($event)->assertRedirect();
        $this->updateEvent($staleEvent)->assertRedirect();

        $this->assertDatabaseCount('event_registration_notifications', 1);
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    public function test_publication_service_is_idempotent(): void
    {
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        EventRegistration::factory()->for($event)->invitedTo()->create(['employee_id' => $employee->id]);
        Queue::fake([SendEventRegistrationNotificationJob::class]);
        $service = app(EventPublicationNotificationService::class);

        $this->assertSame(1, $service->scheduleForPublishedEvent($event));
        $this->assertSame(0, $service->scheduleForPublishedEvent($event));
        $this->assertDatabaseCount('event_registration_notifications', 1);
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    #[TestWith([Event::STATUS_CLOSED])]
    #[TestWith([Event::STATUS_CANCELLED])]
    public function test_terminal_events_cannot_be_republished(string $status): void
    {
        $event = Event::factory()->create(['status' => $status]);
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        $this->updateEvent($event)->assertSessionHasErrors('status');

        $this->assertSame($status, $event->fresh()->status);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_capacity_validation_prevents_publication_and_notifications(): void
    {
        $event = Event::factory()->create();
        EventRegistration::factory()->for($event)->count(2)->create();
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        $this->updateEvent($event, ['capacity' => 1])->assertSessionHasErrors('capacity');

        $this->assertSame(Event::STATUS_DRAFT, $event->fresh()->status);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_event_day_validation_prevents_publication_and_notifications(): void
    {
        $event = Event::factory()->create();
        EventDay::factory()->for($event)->create(['date' => $event->start_date->toDateString()]);
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        $this->updateEvent($event, [
            'start_date' => $event->end_date->copy()->addDay()->toDateString(),
            'end_date' => $event->end_date->copy()->addDays(2)->toDateString(),
        ])->assertSessionHasErrors('start_date');

        $this->assertSame(Event::STATUS_DRAFT, $event->fresh()->status);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_notification_failure_rolls_back_publication(): void
    {
        $event = Event::factory()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        EventRegistration::factory()->for($event)->invitedTo()->create(['employee_id' => $employee->id]);
        Queue::fake([SendEventRegistrationNotificationJob::class]);
        Exceptions::fake();
        EventRegistrationNotification::creating(function (): void {
            throw new RuntimeException('Notification persistence failed.');
        });

        try {
            $this->updateEvent($event)->assertInternalServerError();
        } finally {
            EventRegistrationNotification::flushEventListeners();
        }

        $this->assertSame(Event::STATUS_DRAFT, $event->fresh()->status);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
        Exceptions::assertReported(RuntimeException::class);
    }
}
