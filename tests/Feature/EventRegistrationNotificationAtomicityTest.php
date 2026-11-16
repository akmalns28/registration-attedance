<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\ExternalParticipant;
use App\Services\EventRegistrationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class EventRegistrationNotificationAtomicityTest extends TestCase
{
    use DatabaseMigrations;

    #[TestWith(['employee'])]
    #[TestWith(['external'])]
    public function test_registration_and_invitation_exist_before_commit_but_job_waits(string $type): void
    {
        $event = Event::factory()->published()->create();
        $participant = match ($type) {
            'employee' => Employee::factory()->create(['email' => 'employee@example.test']),
            'external' => ExternalParticipant::factory()->create(['email' => 'external@example.test']),
        };
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        DB::transaction(function () use ($event, $participant, $type): void {
            $service = app(EventRegistrationService::class);
            $result = match ($type) {
                'employee' => $service->registerEmployees($event, [$participant->id]),
                'external' => $service->registerExternals($event, [$participant->id]),
            };

            $this->assertSame(['created' => 1, 'reactivated' => 0, 'skipped' => 0, 'total' => 1], $result);
            $this->assertDatabaseCount('event_registrations', 1);
            $this->assertDatabaseCount('event_registration_notifications', 1);
            $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, EventRegistrationNotification::query()->sole()->status);
            Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
        });

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
        Queue::assertPushedOn('emails', SendEventRegistrationNotificationJob::class);
    }

    public function test_registration_and_notification_roll_back_with_no_dispatch(): void
    {
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        try {
            DB::transaction(function () use ($event, $employee): void {
                app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);
                $this->assertDatabaseCount('event_registration_notifications', 1);

                throw new RuntimeException('Rollback registration.');
            });
            $this->fail('The transaction must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback registration.', $exception->getMessage());
        }

        $this->assertDatabaseCount('event_registrations', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_notification_persistence_failure_rolls_back_registration(): void
    {
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        Queue::fake([SendEventRegistrationNotificationJob::class]);
        EventRegistrationNotification::creating(function (): void {
            throw new RuntimeException('Notification persistence failed.');
        });

        try {
            app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);
            $this->fail('Notification persistence must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification persistence failed.', $exception->getMessage());
        } finally {
            EventRegistrationNotification::flushEventListeners();
        }

        $this->assertDatabaseCount('event_registrations', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_real_database_queue_is_populated_only_after_outermost_commit(): void
    {
        config(['queue.default' => 'database']);
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);

        DB::transaction(function () use ($event, $employee): void {
            DB::transaction(function () use ($event, $employee): void {
                app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);
                $this->assertDatabaseCount('event_registration_notifications', 1);
                $this->assertDatabaseCount('jobs', 0);
            });
            $this->assertDatabaseCount('jobs', 0);
        });

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'emails']);
        $notification = EventRegistrationNotification::query()->sole();
        $this->assertSame((string) DB::table('jobs')->sole()->id, $notification->metadata['queue_job_id']);
    }

    public function test_database_queue_and_notification_are_empty_after_rollback(): void
    {
        config(['queue.default' => 'database']);
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);

        DB::beginTransaction();
        app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);
        DB::rollBack();

        $this->assertDatabaseCount('event_registrations', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queue_outage_preserves_committed_notification_for_recovery(): void
    {
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        Queue::fake([SendEventRegistrationNotificationJob::class])->beforePushing(function (): void {
            throw new RuntimeException('Queue unavailable.');
        });

        app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);

        $this->assertDatabaseCount('event_registrations', 1);
        $this->assertDatabaseHas('event_registration_notifications', ['status' => EventRegistrationNotification::STATUS_PENDING, 'attempts' => 0]);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_draft_registration_does_not_create_an_invitation(): void
    {
        $event = Event::factory()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);

        $this->assertDatabaseCount('event_registrations', 1);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_duplicate_published_registration_does_not_repeat_invitation(): void
    {
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        Queue::fake([SendEventRegistrationNotificationJob::class]);
        $service = app(EventRegistrationService::class);
        $service->registerEmployees($event, [$employee->id, '000'.$employee->id]);

        $result = $service->registerEmployees($event, [$employee->id]);

        $this->assertSame(['created' => 0, 'reactivated' => 0, 'skipped' => 1, 'total' => 1], $result);
        $this->assertDatabaseCount('event_registrations', 1);
        $this->assertDatabaseCount('event_registration_notifications', 1);
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    public function test_published_capacity_failure_creates_no_registration_or_notification(): void
    {
        $event = Event::factory()->published()->create(['capacity' => 1]);
        EventRegistration::factory()->for($event)->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        Queue::fake([SendEventRegistrationNotificationJob::class]);

        try {
            app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);
            $this->fail('Capacity validation must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('participants', $exception->errors());
        }

        $this->assertDatabaseCount('event_registrations', 1);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }
}
