<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventNotificationPageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('events.view', 'web');
        Permission::findOrCreate('events.update', 'web');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.from.address' => 'admin@example.test',
            'queue.default' => 'database',
        ]);
        Queue::fake([SendEventRegistrationNotificationJob::class]);
    }

    private function user(bool $canUpdate = true): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($canUpdate ? ['events.view', 'events.update'] : ['events.view']);

        return $user;
    }

    private function notification(array $attributes = [], ?Event $event = null): EventRegistrationNotification
    {
        $event ??= Event::factory()->create(['status' => Event::STATUS_PUBLISHED]);
        $registration = EventRegistration::factory()->create(['event_id' => $event->id]);

        return EventRegistrationNotification::factory()->create([
            'event_id' => $event->id,
            'event_registration_id' => $registration->id,
            ...$attributes,
        ]);
    }

    public function test_authorized_admin_can_view_notification_history_and_delivery_actions(): void
    {
        $notification = $this->notification(['status' => EventRegistrationNotification::STATUS_SENT, 'sent_at' => now()]);

        $this->actingAs($this->user())->get(route('notifications.index', ['event' => $notification->event->uuid]))
            ->assertOk()->assertSee('Riwayat Notifikasi')->assertSee($notification->registration->registration_number)
            ->assertSee($notification->recipient_email)->assertSee('Kirim ulang')->assertSee('Kirim undangan yang belum dikirim')
            ->assertSee('Email terkonfigurasi')->assertSee('emails');
        Queue::assertNothingPushed();
    }

    public function test_notification_page_requires_authentication_and_view_permission(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_active' => true]))->get(route('notifications.index'))->assertForbidden();
    }

    public function test_read_only_staff_can_view_history_but_cannot_send_retry_or_resend(): void
    {
        $notification = $this->notification();
        $this->actingAs($this->user(false))->get(route('notifications.index', ['event' => $notification->event->uuid]))
            ->assertOk()->assertDontSee('Coba lagi')->assertDontSee('Kirim ulang')->assertDontSee('Mailer:');
        $this->post(route('notifications.retry', $notification))->assertForbidden();
        $this->post(route('notifications.resend', $notification))->assertForbidden();
        $this->post(route('events.notifications.invitations', $notification->event))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_event_status_and_type_filters_are_applied_together(): void
    {
        $matching = $this->notification(['status' => EventRegistrationNotification::STATUS_FAILED]);
        $otherStatus = $this->notification([], $matching->event);
        $otherType = $this->notification(['status' => EventRegistrationNotification::STATUS_FAILED, 'notification_type' => EventRegistrationNotification::TYPE_REMINDER], $matching->event);
        $otherEvent = $this->notification(['status' => EventRegistrationNotification::STATUS_FAILED]);

        $this->actingAs($this->user())->get(route('notifications.index', ['event' => $matching->event->uuid, 'status' => 'failed', 'type' => 'invitation']))
            ->assertOk()->assertSee($matching->registration->registration_number)
            ->assertDontSee($otherStatus->registration->registration_number)
            ->assertDontSee($otherType->registration->registration_number)
            ->assertDontSee($otherEvent->registration->registration_number);
        $this->getJson(route('notifications.index', ['status' => 'unknown']))->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_notification_errors_and_participant_names_are_escaped(): void
    {
        $notification = $this->notification(['last_error' => '<script>privateDiagnostic()</script>']);
        $notification->registration->employee->update(['name' => '<script>participantName()</script>']);

        $this->actingAs($this->user())->get(route('notifications.index'))
            ->assertOk()->assertSee('<script>privateDiagnostic()</script>')->assertSee('<script>participantName()</script>')
            ->assertDontSee('<script>privateDiagnostic()</script>', false)->assertDontSee('<script>participantName()</script>', false);
    }

    public function test_repeated_retry_submissions_queue_only_once_without_resetting_attempts(): void
    {
        $notification = $this->notification(['attempts' => 1]);
        $this->actingAs($this->user());

        $this->post(route('notifications.retry', $notification))->assertRedirect()->assertSessionHas('success');
        $this->post(route('notifications.retry', $notification))->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
        $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, $notification->fresh()->status);
        $this->assertSame(1, $notification->fresh()->attempts);
    }

    #[TestWith(['failed', 1, 'recent'])]
    #[TestWith(['failed', 3, 'old'])]
    #[TestWith(['processing', 1, 'recent'])]
    #[TestWith(['sent', 1, 'old'])]
    #[TestWith(['skipped', 0, 'old'])]
    public function test_retry_preserves_delivery_guards(string $status, int $attempts, string $age): void
    {
        $notification = $this->notification([
            'status' => $status, 'attempts' => $attempts,
            'failed_at' => $age === 'recent' ? now() : now()->subHour(),
            'last_attempt_at' => $age === 'recent' ? now() : now()->subHour(),
        ]);

        $this->actingAs($this->user())->post(route('notifications.retry', $notification))
            ->assertRedirect()->assertSessionHasErrors('notification');

        Queue::assertNothingPushed();
        $this->assertSame($status, $notification->fresh()->status);
        $this->assertSame($attempts, $notification->fresh()->attempts);
    }

    public function test_retry_does_not_duplicate_a_live_delayed_database_job(): void
    {
        Queue::getFacadeRoot()->except([SendEventRegistrationNotificationJob::class]);
        $notification = $this->notification(['status' => EventRegistrationNotification::STATUS_QUEUED, 'queued_at' => now()->subHour()]);
        Queue::connection('database')->later(600, (new SendEventRegistrationNotificationJob($notification->id))->beforeCommit(), '', 'emails');

        $this->actingAs($this->user())->post(route('notifications.retry', $notification))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, $notification->fresh()->attempts);
    }

    public function test_resend_creates_one_new_delivery_using_current_email_and_preserves_original_history(): void
    {
        $notification = $this->notification(['status' => EventRegistrationNotification::STATUS_SENT, 'attempts' => 1, 'sent_at' => now()]);
        $notification->registration->employee->update(['email' => 'corrected@example.test']);
        $this->actingAs($this->user());

        $this->post(route('notifications.resend', $notification))->assertRedirect()->assertSessionHas('success');
        $this->post(route('notifications.resend', $notification))->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
        $this->assertDatabaseCount('event_registration_notifications', 2);
        $resent = EventRegistrationNotification::query()->where('id', '!=', $notification->id)->sole();
        $this->assertSame('corrected@example.test', $resent->recipient_email);
        $this->assertSame($notification->id, $resent->metadata['resend_of']);
        $this->assertSame(0, $resent->attempts);
        $this->assertSame(EventRegistrationNotification::STATUS_SENT, $notification->fresh()->status);
        $this->assertSame(1, $notification->fresh()->attempts);
    }

    public function test_skipped_notification_can_be_deliberately_resent_after_email_is_corrected(): void
    {
        $notification = $this->notification(['status' => EventRegistrationNotification::STATUS_SKIPPED, 'recipient_email' => null]);
        $notification->registration->employee->update(['email' => 'corrected@example.test']);

        $this->actingAs($this->user())->post(route('notifications.resend', $notification))->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 1);
        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
    }

    public function test_cancelled_registration_cannot_be_resent_an_invitation(): void
    {
        $notification = $this->notification(['status' => EventRegistrationNotification::STATUS_SENT]);
        $notification->registration->update(['status' => EventRegistration::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->actingAs($this->user())->post(route('notifications.resend', $notification))
            ->assertRedirect()->assertSessionHasErrors('notification');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('event_registration_notifications', 1);
    }

    public function test_non_delivering_mailer_blocks_manual_delivery_actions(): void
    {
        config(['mail.default' => 'log']);
        $notification = $this->notification();
        $this->actingAs($this->user());

        $this->get(route('notifications.index'))->assertOk()->assertSee('Pengiriman email belum siap');
        $this->post(route('notifications.retry', $notification))->assertSessionHasErrors('notification');
        $this->post(route('events.notifications.invitations', $notification->event))->assertSessionHasErrors('notification');
        $notification->update(['status' => EventRegistrationNotification::STATUS_SENT]);
        $this->post(route('notifications.resend', $notification))->assertSessionHasErrors('notification');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('event_registration_notifications', 1);
    }

    public function test_bulk_invitations_queue_missing_and_pending_deliveries_once_and_preserve_sent_history(): void
    {
        $event = Event::factory()->create(['status' => Event::STATUS_PUBLISHED]);
        $pending = $this->notification([], $event);
        $pending->update(['dedupe_key' => 'event:'.$event->id.':registration:'.$pending->event_registration_id.':invitation:v1']);
        $sent = $this->notification(['status' => EventRegistrationNotification::STATUS_SENT], $event);
        $sent->update(['dedupe_key' => 'event:'.$event->id.':registration:'.$sent->event_registration_id.':invitation:v1']);
        EventRegistration::factory()->create(['event_id' => $event->id]);
        EventRegistration::factory()->cancelled()->create(['event_id' => $event->id]);
        $withoutEmail = EventRegistration::factory()->create(['event_id' => $event->id]);
        $withoutEmail->employee->update(['email' => null]);
        $this->actingAs($this->user());

        $this->post(route('events.notifications.invitations', $event))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('events.notifications.invitations', $event))->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(SendEventRegistrationNotificationJob::class, 2);
        $this->assertDatabaseCount('event_registration_notifications', 4);
        $this->assertSame(EventRegistrationNotification::STATUS_SENT, $sent->fresh()->status);
        $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, $pending->fresh()->status);
        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $withoutEmail->notifications()->sole()->status);
    }

    public function test_bulk_invitations_require_published_event(): void
    {
        $event = Event::factory()->create(['status' => Event::STATUS_DRAFT]);
        EventRegistration::factory()->create(['event_id' => $event->id]);

        $this->actingAs($this->user())->post(route('events.notifications.invitations', $event))
            ->assertRedirect()->assertSessionHasErrors('notification');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_dispatch_outage_keeps_notification_pending_and_reports_failure_to_admin(): void
    {
        $notification = $this->notification();
        Queue::getFacadeRoot()->beforePushing(function (): void {
            throw new RuntimeException('Queue unavailable');
        });

        $this->actingAs($this->user())->post(route('notifications.retry', $notification))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(EventRegistrationNotification::STATUS_PENDING, $notification->fresh()->status);
        $this->assertSame(0, $notification->fresh()->attempts);
        Queue::assertNothingPushed();
    }
}
