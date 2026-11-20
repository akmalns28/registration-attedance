<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Services\EventNotificationRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class RecoverEventNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        config(['queue.default' => 'database']);
        Queue::fake([SendEventRegistrationNotificationJob::class]);
    }

    private function notification(string $status = EventRegistrationNotification::STATUS_PENDING, array $attributes = []): EventRegistrationNotification
    {
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'participant@example.test']);
        $registration = EventRegistration::factory()->for($event)->invitedTo()->create(['employee_id' => $employee->id]);

        return EventRegistrationNotification::factory()->create(array_replace([
            'event_id' => $event->id,
            'event_registration_id' => $registration->id,
            'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
            'recipient_email' => 'participant@example.test',
            'status' => $status,
            'queued_at' => $status === EventRegistrationNotification::STATUS_QUEUED ? now()->subHour() : null,
            'last_attempt_at' => $status === EventRegistrationNotification::STATUS_PROCESSING ? now()->subHour() : null,
            'failed_at' => $status === EventRegistrationNotification::STATUS_FAILED ? now()->subHour() : null,
        ], $attributes));
    }

    public function test_dry_run_reports_eligible_and_ineligible_without_writes_or_dispatch(): void
    {
        $eligible = $this->notification();
        $ineligible = $this->notification(attributes: ['recipient_email' => null]);
        $before = EventRegistrationNotification::query()->orderBy('id')->get()->toArray();

        $this->artisan('notifications:recover', ['--dry-run' => true])
            ->expectsOutputToContain('Checked: 2; Eligible: 1; Recovered: 0; Skipped: 0; Ineligible: 1')
            ->assertExitCode(0);

        $this->assertSame($before, EventRegistrationNotification::query()->orderBy('id')->get()->toArray());
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    #[TestWith([EventRegistrationNotification::STATUS_PENDING])]
    #[TestWith([EventRegistrationNotification::STATUS_QUEUED])]
    #[TestWith([EventRegistrationNotification::STATUS_PROCESSING])]
    #[TestWith([EventRegistrationNotification::STATUS_FAILED])]
    public function test_abandoned_delivery_is_requeued_once(string $status): void
    {
        $notification = $this->notification($status);

        $this->artisan('notifications:recover')->expectsOutputToContain('Recovered: 1')->assertExitCode(0);
        $this->artisan('notifications:recover')->expectsOutputToContain('Recovered: 0')->assertExitCode(0);

        $notification->refresh();
        $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, $notification->status);
        $this->assertSame(1, $notification->metadata['dispatch_generation']);
        $this->assertSame(0, $notification->attempts);
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
        Queue::assertPushedOn('emails', SendEventRegistrationNotificationJob::class);
    }

    #[TestWith([EventRegistrationNotification::STATUS_SENT])]
    #[TestWith([EventRegistrationNotification::STATUS_SKIPPED])]
    public function test_terminal_deliveries_are_not_requeued(string $status): void
    {
        $notification = $this->notification($status);

        $this->artisan('notifications:recover')->assertExitCode(0);

        $this->assertSame($status, $notification->fresh()->status);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    #[TestWith([EventRegistrationNotification::TYPE_INVITATION])]
    #[TestWith([EventRegistrationNotification::TYPE_REACTIVATION])]
    #[TestWith([EventRegistrationNotification::TYPE_REMINDER])]
    public function test_active_registration_emails_are_skipped_after_cancellation(string $type): void
    {
        $notification = $this->notification(attributes: ['notification_type' => $type]);
        $notification->registration->update(['status' => EventRegistration::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->artisan('notifications:recover')->expectsOutputToContain('Ineligible: 1')->assertExitCode(0);

        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_obsolete_cancellation_is_skipped_after_reactivation(): void
    {
        $notification = $this->notification(attributes: ['notification_type' => EventRegistrationNotification::TYPE_CANCELLATION]);

        $this->artisan('notifications:recover')->assertExitCode(0);

        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_cancellation_can_still_be_delivered_when_the_event_has_closed(): void
    {
        $notification = $this->notification(attributes: ['notification_type' => EventRegistrationNotification::TYPE_CANCELLATION]);
        $notification->registration->update(['status' => EventRegistration::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $notification->registration->event->update(['status' => Event::STATUS_CLOSED]);

        $this->artisan('notifications:recover')->assertExitCode(0);

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    public function test_closed_event_invitation_is_not_recovered(): void
    {
        $notification = $this->notification();
        $notification->registration->event->update(['status' => Event::STATUS_CLOSED]);

        $this->artisan('notifications:recover')->assertExitCode(0);

        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    #[TestWith([null])]
    #[TestWith(['not-an-email'])]
    #[TestWith(['   '])]
    public function test_invalid_or_missing_recipient_is_not_recovered(?string $email): void
    {
        $notification = $this->notification(attributes: ['recipient_email' => $email]);

        $this->artisan('notifications:recover')->assertExitCode(0);

        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_retry_limit_is_enforced_without_resetting_attempts(): void
    {
        $notification = $this->notification(EventRegistrationNotification::STATUS_FAILED, ['attempts' => 3]);

        $this->artisan('notifications:recover')->expectsOutputToContain('Exhausted: 1')->assertExitCode(0);

        $this->assertSame(3, $notification->fresh()->attempts);
        $this->assertSame(EventRegistrationNotification::STATUS_FAILED, $notification->fresh()->status);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_exhausted_abandoned_processing_is_marked_failed_without_delivery(): void
    {
        $notification = $this->notification(EventRegistrationNotification::STATUS_PROCESSING, ['attempts' => 3]);

        $this->artisan('notifications:recover')->assertExitCode(0);

        $this->assertSame(EventRegistrationNotification::STATUS_FAILED, $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->failed_at);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_retry_backoff_and_future_schedule_are_respected(): void
    {
        $this->notification(EventRegistrationNotification::STATUS_FAILED, ['attempts' => 1, 'failed_at' => now()]);
        $this->notification(attributes: ['scheduled_for' => now()->addDay()]);

        $this->artisan('notifications:recover')->expectsOutputToContain('Recovered: 0')->assertExitCode(0);
        $this->travel(61)->seconds();
        $this->artisan('notifications:recover')->expectsOutputToContain('Recovered: 1')->assertExitCode(0);

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    #[TestWith([null, 3600])]
    #[TestWith([0, 0])]
    #[TestWith([-3600, 0])]
    public function test_existing_database_job_is_not_duplicated_even_when_delayed_or_reservation_expired(?int $reservedOffset, int $availableOffset): void
    {
        $notification = $this->notification(EventRegistrationNotification::STATUS_QUEUED);
        Queue::getFacadeRoot()->except([SendEventRegistrationNotificationJob::class]);
        Bus::dispatch((new SendEventRegistrationNotificationJob($notification->id))->onConnection('database')->beforeCommit());
        DB::table('jobs')->update([
            'reserved_at' => $reservedOffset === null ? null : now()->timestamp + $reservedOffset,
            'available_at' => now()->timestamp + $availableOffset,
        ]);

        $this->artisan('notifications:recover')->expectsOutputToContain('Recovered: 0')->assertExitCode(0);

        $this->assertDatabaseCount('jobs', 1);
        $this->assertNull($notification->fresh()->metadata);
    }

    public function test_recovery_fences_older_jobs_and_permanent_failure_callbacks(): void
    {
        $notification = $this->notification(EventRegistrationNotification::STATUS_PROCESSING, [
            'metadata' => ['dispatch_generation' => 4, 'processing_token' => 'old-worker'],
        ]);
        Mail::fake();

        $this->artisan('notifications:recover')->assertExitCode(0);
        $oldJob = new SendEventRegistrationNotificationJob($notification->id, 4);
        $oldJob->handle();
        $oldJob->failed(new RuntimeException('Obsolete job failed.'));

        $this->assertSame(5, $notification->fresh()->metadata['dispatch_generation']);
        $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_limit_bounds_recovered_deliveries(): void
    {
        $this->notification();
        $this->notification();

        $this->artisan('notifications:recover', ['--limit' => 1])->expectsOutputToContain('Recovered: 1')->assertExitCode(0);

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
        $this->assertSame(1, EventRegistrationNotification::query()->pending()->count());
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['abc'])]
    #[TestWith(['1.5'])]
    #[TestWith(['10001'])]
    public function test_invalid_limit_is_rejected(string $limit): void
    {
        $this->artisan('notifications:recover', ['--limit' => $limit])->expectsOutputToContain('must be an integer')->assertExitCode(1);

        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_reentrant_recovery_does_not_schedule_the_same_delivery_again(): void
    {
        $this->notification();
        $nested = null;
        Queue::beforePushing(function () use (&$nested): void {
            $nested = app(EventNotificationRecoveryService::class)->recover();
        });

        $this->artisan('notifications:recover')->assertExitCode(0);

        $this->assertSame(0, $nested['recovered']);
        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
    }

    public function test_dispatch_failure_leaves_pending_notification_and_reports_error(): void
    {
        $notification = $this->notification();
        Queue::beforePushing(function (): void {
            throw new RuntimeException('Queue unavailable.');
        });

        $this->artisan('notifications:recover')->expectsOutputToContain('Errors: 1')->assertExitCode(1);

        $this->assertSame(EventRegistrationNotification::STATUS_PENDING, $notification->fresh()->status);
        $this->assertSame(0, $notification->fresh()->attempts);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }

    public function test_uninspectable_queue_backend_is_left_for_manual_review(): void
    {
        $notification = $this->notification(EventRegistrationNotification::STATUS_QUEUED, [
            'metadata' => ['queue_connection' => 'redis'],
        ]);

        $this->artisan('notifications:recover')->expectsOutputToContain('Skipped: 1')->assertExitCode(0);

        $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, $notification->fresh()->status);
        Queue::assertNotPushed(SendEventRegistrationNotificationJob::class);
    }
}
