<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Mail\EventRegistrationMail;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
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

        $registration = EventRegistration::factory()->invitedTo()->create([
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
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection could not be established with the SMTP host.'));

        try {
            (new SendEventRegistrationNotificationJob($notification->id))->handle();
            $this->fail('The SMTP exception must reach the queue worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Event registration email delivery failed (notification '.$notification->id.').', $exception->getMessage());
        }

        $failed = $notification->fresh();
        $this->assertSame(EventRegistrationNotification::STATUS_FAILED, $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertSame('SMTP connection failed. Check relay reachability.', $failed->last_error);
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
        $notification->update(['attempts' => 3, 'last_error' => 'SMTP server rejected the message. SMTP reply 550 5.7.1.']);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $this->assertSame(EventRegistrationNotification::STATUS_FAILED, $notification->fresh()->status);
        $this->assertSame(3, $notification->fresh()->attempts);
        $this->assertSame('SMTP server rejected the message. SMTP reply 550 5.7.1.', $notification->fresh()->last_error);
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
        $smtpError = new UnexpectedResponseException('Expected response code "250" but got code "535", with message "535 5.7.8 smtp://operator:relay-secret@private-relay.test participant@example.com".', 535);
        Mail::shouldReceive('to')->once()->andThrow($smtpError);
        $job = new SendEventRegistrationNotificationJob($notification->id);

        try {
            $job->handle();
            $this->fail('The worker must receive a safe delivery exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Event registration email delivery failed (notification '.$notification->id.').', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $job->failed($exception);
        }

        $diagnostic = 'SMTP authentication failed. SMTP reply 535 5.7.8.';
        $this->assertSame($diagnostic, $notification->fresh()->last_error);
        Log::shouldHaveReceived('warning')->once()->with('Event registration email delivery failed.', [
            'notification_id' => $notification->id,
            'attempts' => 1,
            'exception_type' => UnexpectedResponseException::class,
            'failure' => $diagnostic,
        ]);
    }

    public function test_database_worker_retry_uses_the_current_logo_and_invitation_dates(): void
    {
        $this->freezeTime();
        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.port' => 587,
            'mail.from.address' => 'admin@example.test', 'event_notifications.backoff' => [60]]);
        Storage::fake('local');
        $firstLogo = UploadedFile::fake()->image('first.png', 40, 20)->store('event-logos', 'local');
        $newLogo = UploadedFile::fake()->image('replacement.jpg', 50, 30)->store('event-logos', 'local');
        $notification = $this->notification();
        $event = $notification->registration->event;
        $event->update(['start_date' => '2027-01-15', 'end_date' => '2027-01-17',
            'logo_path' => $firstLogo, 'location' => 'Hotel Kota', 'google_maps_url' => 'https://maps.google.com/?q=Kota']);
        $originalDay = $notification->registration->invitedDays()->firstOrFail();
        $originalDay->update(['date' => '2027-01-15']);
        $replacementDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 3, 'title' => 'Hidden Day Title']);
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->ordered()->andReturnUsing(function (Email $message): never {
            $this->assertStringContainsString('15 Januari 2027', $message->getHtmlBody());
            $this->assertStringNotContainsString('17 Januari 2027', $message->getHtmlBody());

            throw new UnexpectedResponseException('Expected response code "250" but got code "451", with message "451 4.7.1 retry later".', 451);
        });
        $transport->shouldReceive('send')->once()->ordered()->andReturnUsing(function (Email $message, Envelope $envelope) use ($newLogo): SentMessage {
            $html = $message->getHtmlBody();
            $this->assertStringContainsString('17 Januari 2027', $html);
            $this->assertStringNotContainsString('15 Januari 2027', $html);
            $this->assertStringContainsString('src="cid:', $html);
            $this->assertStringContainsString('href="https://maps.google.com/?q=Kota"', $html);
            $this->assertStringNotContainsString('Hidden Day Title', $html);
            $this->assertCount(2, $message->getAttachments());
            $this->assertSame(Storage::disk('local')->get($newLogo), $message->getAttachments()[0]->getBody());
            $this->assertStringStartsWith('registration-', $message->getAttachments()[1]->getFilename());

            return new SentMessage($message, $envelope);
        });
        $mailManager = new MailManager($this->app);
        $mailManager->mailer('smtp')->setSymfonyTransport($transport);
        Mail::swap($mailManager);

        $job = (new SendEventRegistrationNotificationJob($notification->id))->beforeCommit();
        Queue::connection('database')->push($job, '', 'emails');
        $workerOptions = ['connection' => 'database', '--queue' => 'emails', '--once' => true, '--sleep' => 0];
        $this->artisan('queue:work', $workerOptions)->assertSuccessful();
        $notification->refresh();
        $this->assertTrue($notification->isFailed());
        $this->assertSame('SMTP server reported a temporary failure. SMTP reply 451 4.7.1.', $notification->last_error);
        $this->assertSame(1, $notification->attempts);

        $event->update(['logo_path' => $newLogo]);
        $notification->registration->invitedDays()->updateExistingPivot($originalDay->id, ['revoked_at' => now()]);
        $notification->registration->invitedDays()->attach($replacementDay);
        $this->travel(61)->seconds();
        $this->artisan('queue:work', $workerOptions)->assertSuccessful();
        $job->handle();
        $notification->refresh();
        $this->assertTrue($notification->isSent());
        $this->assertSame(2, $notification->attempts);
        $this->assertNull($notification->last_error);
        $this->assertDatabaseCount('jobs', 0);
    }
}
