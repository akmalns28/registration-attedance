<?php

namespace Tests\Feature;

use App\Http\Controllers\EventParticipantController;
use App\Jobs\SendEventRegistrationNotificationJob;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\User;
use App\Services\EventPublicationNotificationService;
use App\Services\EventRegistrationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EventRegistrationNotificationIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    private EventRegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->service = app(EventRegistrationService::class);
    }

    private function createRegistration(string $eventStatus = Event::STATUS_PUBLISHED): EventRegistration
    {
        $event = Event::factory()->create([
            'status' => $eventStatus,
        ]);

        /*
    |--------------------------------------------------------------------------
    | Create Employee With Valid Email
    |--------------------------------------------------------------------------
    */

        $employee = Employee::factory()->create([
            'email' => 'participant@example.com',
            'is_active' => true,
        ]);

        /*
    |--------------------------------------------------------------------------
    | Create Registration
    |--------------------------------------------------------------------------
    */

        return EventRegistration::factory()
            ->employee()
            ->create([
                'event_id' => $event->id,
                'employee_id' => $employee->id,
                'status' => EventRegistration::STATUS_REGISTERED,
            ]);
    }

    /**
     * Cancellation and its notification must roll back together.
     */
    public function test_cancellation_rolls_back_with_outer_transaction(): void
    {
        $registration = $this->createRegistration();

        try {
            DB::transaction(function () use ($registration) {
                $this->service->cancel($registration);

                throw new RuntimeException('Simulated rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated rollback', $exception->getMessage());
        }

        $this->assertSame(EventRegistration::STATUS_REGISTERED, $registration->fresh()->status);

        $this->assertDatabaseMissing('event_registration_notifications', [
            'event_registration_id' => $registration->id,
            'notification_type' => EventRegistrationNotification::TYPE_CANCELLATION,
        ]);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    /**
     * Reactivation and its notification must roll back together.
     */
    public function test_reactivation_rolls_back_with_outer_transaction(): void
    {
        $registration = $this->createRegistration();

        $this->service->cancel($registration);

        $registration->refresh();

        $this->assertTrue($registration->isCancelled());

        try {
            DB::transaction(function () use ($registration) {
                $this->service->reactivate($registration);

                throw new RuntimeException('Simulated rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated rollback', $exception->getMessage());
        }

        $this->assertTrue($registration->fresh()->isCancelled());

        $this->assertDatabaseMissing('event_registration_notifications', [
            'event_registration_id' => $registration->id,
            'notification_type' => EventRegistrationNotification::TYPE_REACTIVATION,
        ]);

        $this->assertSame(1, $registration->notifications()->where('notification_type', EventRegistrationNotification::TYPE_CANCELLATION)->count());
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    /**
     * Every real cancellation/reactivation cycle creates
     * a distinct notification.
     */
    public function test_multiple_status_cycles_have_unique_dedupe_keys(): void
    {
        $registration = $this->createRegistration();

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->service->cancel($registration->fresh());

            $this->service->reactivate($registration->fresh());
        }

        $notifications = $registration->notifications()->get();

        $this->assertCount(6, $notifications);

        $this->assertCount(6, $notifications->pluck('dedupe_key')->unique());

        $this->assertSame(3, $notifications->where('notification_type', EventRegistrationNotification::TYPE_CANCELLATION)->count());

        $this->assertSame(3, $notifications->where('notification_type', EventRegistrationNotification::TYPE_REACTIVATION)->count());
    }

    /**
     * A successful transaction persists the registration
     * status and notification together.
     */
    public function test_successful_cancellation_commits_notification(): void
    {
        $registration = $this->createRegistration();

        DB::transaction(function () use ($registration) {
            $this->service->cancel($registration);
        });

        $this->assertTrue($registration->fresh()->isCancelled());

        $this->assertDatabaseHas('event_registration_notifications', [
            'event_registration_id' => $registration->id,
            'notification_type' => EventRegistrationNotification::TYPE_CANCELLATION,
        ]);
    }

    /**
     * Repeated cancellation must not produce extra records.
     */
    public function test_repeated_cancellation_does_not_duplicate_notification(): void
    {
        $registration = $this->createRegistration();

        $this->service->cancel($registration);

        $this->service->cancel($registration->fresh());

        $this->assertSame(1, $registration->notifications()->where('notification_type', EventRegistrationNotification::TYPE_CANCELLATION)->count());
    }

    public function test_queue_dispatch_occurs_after_transaction_commit(): void
    {
        $registration = $this->createRegistration();

        /*
    |--------------------------------------------------------------------------
    | Execute Transaction
    |--------------------------------------------------------------------------
    */

        DB::transaction(function () use ($registration) {
            $this->service->cancel($registration);

            /*
        |--------------------------------------------------------------------------
        | Verify Notification Created
        |--------------------------------------------------------------------------
        */

            $notification = EventRegistrationNotification::query()->where('event_registration_id', $registration->id)->where('notification_type', EventRegistrationNotification::TYPE_CANCELLATION)->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Verify Recipient
        |--------------------------------------------------------------------------
        */

            $this->assertSame('participant@example.com', $notification->recipient_email);

            /*
        |--------------------------------------------------------------------------
        | Verify Notification Status
        |--------------------------------------------------------------------------
        */

            $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, $notification->status);

            /*
        |--------------------------------------------------------------------------
        | Job Must Not Dispatch Before Commit
        |--------------------------------------------------------------------------
        */

            Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
        });

        /*
    |--------------------------------------------------------------------------
    | Verify Job Dispatched After Commit
    |--------------------------------------------------------------------------
    */

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    public function test_deleted_old_history_does_not_reuse_status_dedupe_keys(): void
    {
        $registration = $this->createRegistration();
        $this->service->cancel($registration);
        $firstCancellation = $registration->notifications()->where('notification_type', EventRegistrationNotification::TYPE_CANCELLATION)->sole();
        $this->service->reactivate($registration);
        $this->service->cancel($registration);
        $this->service->reactivate($registration);
        $firstCancellation->delete();

        $this->service->cancel($registration);

        $this->assertSame(2, $registration->notifications()->where('notification_type', EventRegistrationNotification::TYPE_CANCELLATION)->count());
        $this->assertSame(4, $registration->notifications()->count());
        $this->assertSame(4, $registration->notifications()->pluck('dedupe_key')->unique()->count());
    }

    public function test_repeated_status_scheduling_returns_the_same_transition_notification(): void
    {
        $registration = $this->createRegistration();
        $this->service->cancel($registration);
        $first = $registration->notifications()->sole();
        $scheduler = app(EventPublicationNotificationService::class);

        $second = $scheduler->scheduleStatusChange($registration, EventRegistrationNotification::TYPE_CANCELLATION);
        $third = $scheduler->scheduleStatusChange($registration, EventRegistrationNotification::TYPE_CANCELLATION);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertDatabaseCount('event_registration_notifications', 1);
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    public function test_repeated_reactivation_does_not_duplicate_the_transition_notification(): void
    {
        $registration = $this->createRegistration();
        $uuid = $registration->uuid;
        $this->service->cancel($registration);
        $this->service->reactivate($registration);

        $this->service->reactivate($registration);

        $this->assertSame($uuid, $registration->fresh()->uuid);
        $this->assertSame(1, $registration->notifications()->where('notification_type', EventRegistrationNotification::TYPE_REACTIVATION)->count());
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 2);
    }

    public function test_stale_registered_binding_does_not_prevent_reactivation(): void
    {
        $registration = $this->createRegistration();
        $this->service->cancel($registration);
        $this->authorizeParticipantChanges();

        app(EventParticipantController::class)->reactivate($registration->event, $registration);

        $this->assertSame(EventRegistration::STATUS_REGISTERED, $registration->fresh()->status);
        $this->assertSame(1, $registration->notifications()->where('notification_type', EventRegistrationNotification::TYPE_REACTIVATION)->count());
    }

    public function test_stale_cancelled_binding_does_not_prevent_cancellation(): void
    {
        $registration = $this->createRegistration();
        $this->service->cancel($registration);
        $stale = $registration->fresh();
        $this->service->reactivate($registration);
        $this->authorizeParticipantChanges();

        app(EventParticipantController::class)->destroy($registration->event, $stale);

        $this->assertSame(EventRegistration::STATUS_CANCELLED, $registration->fresh()->status);
        $this->assertSame(2, $registration->notifications()->where('notification_type', EventRegistrationNotification::TYPE_CANCELLATION)->count());
    }

    private function authorizeParticipantChanges(): void
    {
        Permission::findOrCreate('events.update', 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('events.update');
        $this->actingAs($user);
    }
}
