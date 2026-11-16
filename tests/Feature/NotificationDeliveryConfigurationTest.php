<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Services\EventNotificationTransport;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NotificationDeliveryConfigurationTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['log'])]
    #[TestWith(['array'])]
    public function test_non_delivering_mailers_never_record_success_or_consume_delivery_attempts(string $mailer): void
    {
        config(['mail.default' => $mailer]);
        Mail::fake();
        $registration = EventRegistration::factory()->for(Event::factory()->state(['status' => Event::STATUS_PUBLISHED]))->create();
        $notification = EventRegistrationNotification::factory()->queued()->create([
            'event_id' => $registration->event_id,
            'event_registration_id' => $registration->id,
        ]);

        (new SendEventRegistrationNotificationJob($notification->id))->handle();

        $notification->refresh();
        $this->assertSame(EventRegistrationNotification::STATUS_PENDING, $notification->status);
        $this->assertSame(0, $notification->attempts);
        $this->assertNull($notification->sent_at);
        $this->assertNull($notification->queued_at);
        $this->assertNotNull($notification->last_error);
        Mail::assertNothingSent();
    }

    public function test_ip_authenticated_smtp_relay_does_not_require_username_or_password(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'admin@ptaun.com',
        ]);

        $this->assertNull(app(EventNotificationTransport::class)->problem());
    }

    #[TestWith(['mail.from.address', 'invalid'])]
    #[TestWith(['mail.mailers.smtp.host', ''])]
    #[TestWith(['mail.mailers.smtp.port', 0])]
    #[TestWith(['mail.mailers.smtp.port', 65536])]
    public function test_invalid_delivery_configuration_is_reported(string $key, mixed $value): void
    {
        config(['mail.default' => 'smtp', $key => $value]);

        $this->assertNotNull(app(EventNotificationTransport::class)->problem());
    }

    public function test_log_fallback_cannot_silently_record_success_after_smtp_failure(): void
    {
        config(['mail.default' => 'failover', 'mail.mailers.failover.mailers' => ['smtp', 'log']]);

        $this->assertNotNull(app(EventNotificationTransport::class)->problem());
    }

    public function test_development_worker_listens_to_the_email_queue_with_notification_retry_policy(): void
    {
        $command = collect(DevCommands::commands())->firstWhere('name', 'queue');

        $this->assertNotNull($command);
        $this->assertStringContainsString('queue:listen', $command['command']);
        $this->assertStringContainsString('emails,default', $command['command']);
        $this->assertStringContainsString('--tries=3', $command['command']);
        $this->assertStringContainsString('--timeout=60', $command['command']);
    }
}
