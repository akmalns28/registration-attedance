<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Mail\EventRegistrationMail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SendEventRegistrationNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    private function notification(string $status = EventRegistrationNotification::STATUS_QUEUED): EventRegistrationNotification
    {
        $event = Event::factory()->create([
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
        ]);

        return EventRegistrationNotification::factory()->create([
            'event_id' => $event->id,
            'event_registration_id' => $registration->id,
            'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
            'recipient_email' => 'participant@example.com',
            'status' => $status,
        ]);
    }

    public function test_job_sends_email(): void
    {
        $notification = $this->notification();

        $job = new SendEventRegistrationNotificationJob($notification->id);

        $job->handle();

        Mail::assertSent(EventRegistrationMail::class);

        $this->assertTrue($notification->fresh()->isSent());
    }

    public function test_sent_notification_is_not_sent_twice(): void
    {
        $notification = $this->notification(EventRegistrationNotification::STATUS_SENT);

        $job = new SendEventRegistrationNotificationJob($notification->id);

        $job->handle();

        Mail::assertNothingSent();
    }

    public function test_missing_email_is_skipped(): void
    {
        $notification = $this->notification();

        $notification->update([
            'recipient_email' => null,
        ]);

        $job = new SendEventRegistrationNotificationJob($notification->id);

        $job->handle();

        Mail::assertNothingSent();

        $this->assertTrue($notification->fresh()->isSkipped());
    }

    public function test_job_increments_attempt_count(): void
    {
        $notification = $this->notification();

        $job = new SendEventRegistrationNotificationJob($notification->id);

        $job->handle();

        $this->assertSame(1, $notification->fresh()->attempts);
    }

    public function test_cancelled_registration_cannot_receive_invitation(): void
    {
        $notification = $this->notification();

        $notification->registration->update([
            'status' => EventRegistration::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $job = new SendEventRegistrationNotificationJob($notification->id);

        $job->handle();

        Mail::assertNothingSent();

        $this->assertTrue($notification->fresh()->isSkipped());
    }

    #[TestWith(['not-an-email'])]
    #[TestWith(['   '])]
    public function test_invalid_recipient_is_skipped_without_attempting_delivery(string $email): void
    {
        $notification = $this->notification();
        $notification->update(['recipient_email' => $email]);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        $this->assertSame(0, $notification->fresh()->attempts);
        Mail::assertNothingSent();
    }

    public function test_missing_notification_is_a_safe_no_op(): void
    {
        (new SendEventRegistrationNotificationJob(PHP_INT_MAX))->handle();

        Mail::assertNothingSent();
    }

    #[TestWith([Event::STATUS_DRAFT])]
    #[TestWith([Event::STATUS_CLOSED])]
    #[TestWith([Event::STATUS_CANCELLED])]
    public function test_inactive_event_invitation_is_skipped(string $status): void
    {
        $notification = $this->notification();
        $notification->registration->event->update(['status' => $status]);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_notification_event_mismatch_is_skipped(): void
    {
        $notification = $this->notification();
        $otherEvent = Event::factory()->published()->create();
        $notification->update(['event_id' => $otherEvent->id]);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_overlapping_worker_sends_once_and_smtp_has_no_additional_transaction(): void
    {
        $notification = $this->notification();
        $transactionLevel = DB::transactionLevel();
        $pendingMail = Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andReturnUsing(function () use ($notification, $transactionLevel): void {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertSame(EventRegistrationNotification::STATUS_PROCESSING, $notification->fresh()->status);
            $duplicate = new SendEventRegistrationNotificationJob($notification->id);
            $duplicate->handle();
            $duplicate->failed(new RuntimeException('A competing job failed.'));
            $this->assertSame(EventRegistrationNotification::STATUS_PROCESSING, $notification->fresh()->status);
        });
        Mail::shouldReceive('to')->once()->with('participant@example.com')->andReturn($pendingMail);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_SENT, $notification->fresh()->status);
        $this->assertSame(1, $notification->fresh()->attempts);
        $this->assertNotNull($notification->fresh()->sent_at);
    }

    public function test_failure_is_recorded_and_a_retry_preserves_attempt_history(): void
    {
        $this->freezeTime();
        $notification = $this->notification();
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable.'));

        try {
            (new SendEventRegistrationNotificationJob($notification->id))->handle();
            $this->fail('The SMTP exception must reach the queue worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Event registration email delivery failed (notification '.$notification->id.').', $exception->getMessage());
        }

        $failed = $notification->fresh();
        $this->assertSame(EventRegistrationNotification::STATUS_FAILED, $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertSame('SMTP unavailable.', $failed->last_error);
        $this->assertNotNull($failed->failed_at);
        $this->assertNotNull($failed->last_attempt_at);
        $this->assertNull($failed->sent_at);

        Mail::swap($mailFake);
        $this->travel(61)->seconds();
        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $sent = $notification->fresh();
        $this->assertSame(EventRegistrationNotification::STATUS_SENT, $sent->status);
        $this->assertSame(2, $sent->attempts);
        $this->assertNull($sent->failed_at);
        $this->assertNull($sent->last_error);
        $this->assertNotNull($sent->sent_at);
        Mail::assertSent(EventRegistrationMail::class, 1);
    }

    public function test_notification_retry_limit_prevents_further_smtp_attempts(): void
    {
        $notification = $this->notification();
        $notification->update(['attempts' => 3]);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_FAILED, $notification->fresh()->status);
        $this->assertSame(3, $notification->fresh()->attempts);
        Mail::assertNothingSent();
    }

    #[TestWith([EventRegistrationNotification::STATUS_SENT])]
    #[TestWith([EventRegistrationNotification::STATUS_SKIPPED])]
    public function test_permanent_failure_callback_preserves_terminal_state(string $status): void
    {
        $notification = $this->notification($status);

        (new SendEventRegistrationNotificationJob($notification->id))->failed(new RuntimeException('Late failure callback.'));

        $this->assertSame($status, $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_stale_processing_claim_can_be_retried(): void
    {
        $notification = $this->notification(EventRegistrationNotification::STATUS_PROCESSING);
        $notification->update([
            'last_attempt_at' => now()->subHour(),
            'attempts' => 1,
            'metadata' => ['processing_token' => 'abandoned-worker'],
        ]);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_SENT, $notification->fresh()->status);
        $this->assertSame(2, $notification->fresh()->attempts);
        Mail::assertSent(EventRegistrationMail::class, 1);
    }

    public function test_obsolete_worker_cannot_overwrite_a_newer_claim(): void
    {
        $notification = $this->notification();
        $pendingMail = Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andReturnUsing(function () use ($notification): void {
            $notification->refresh();
            $metadata = $notification->metadata;
            $metadata['processing_token'] = 'replacement-worker';
            $notification->update(['metadata' => $metadata]);
        });
        Mail::shouldReceive('to')->once()->andReturn($pendingMail);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_PROCESSING, $notification->fresh()->status);
        $this->assertSame('replacement-worker', $notification->fresh()->metadata['processing_token']);
        $this->assertNull($notification->fresh()->sent_at);
    }

    public function test_job_retry_and_timeout_configuration_is_applied(): void
    {
        config([
            'event_notifications.tries' => 5,
            'event_notifications.timeout' => 45,
            'event_notifications.queue' => 'custom-emails',
            'event_notifications.backoff' => [10, 30, 60],
        ]);

        $job = new SendEventRegistrationNotificationJob(123);

        $this->assertSame(5, $job->tries);
        $this->assertSame(45, $job->timeout);
        $this->assertSame('custom-emails', $job->queue);
        $this->assertSame([10, 30, 60], $job->backoff());
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_smtp_recipient_details_are_not_exposed_to_worker_logs(): void
    {
        $notification = $this->notification();
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP rejected participant@example.com'));
        $job = new SendEventRegistrationNotificationJob($notification->id);

        try {
            $job->handle();
            $this->fail('The worker must receive a safe delivery exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Event registration email delivery failed (notification '.$notification->id.').', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $job->failed($exception);
        }

        $this->assertSame('SMTP rejected participant@example.com', $notification->fresh()->last_error);
        Log::shouldHaveReceived('warning')->once()->with('Event registration email delivery failed.', [
            'notification_id' => $notification->id,
            'attempts' => 1,
            'exception_type' => RuntimeException::class,
        ]);
    }
}
