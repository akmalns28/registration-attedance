<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventPoster;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\EventSchedule;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\EventNotificationEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Zxing\QrReader;

class EventBulkInvitationResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'event_notifications.connection' => 'database',
            'event_notifications.queue' => 'emails',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.from.address' => 'rams@example.test',
        ]);
    }

    public function test_a_previously_sent_invitation_can_be_queued_again_for_a_checked_in_participant(): void
    {
        Mail::fake();
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->published()->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        $registration = EventRegistration::factory()->for($event)->for($employee)->invitedTo()->create();
        $day = $registration->invitedDays()->sole();
        $attendance = Attendance::factory()->for($registration, 'registration')->for($day)->create();
        $previous = EventRegistrationNotification::factory()->for($registration, 'registration')->sent()->create();
        $registrationAttributes = $registration->fresh()->getAttributes();
        $attendanceAttributes = $attendance->fresh()->getAttributes();
        $history = $previous->fresh()->getAttributes();

        $this->resend($event, [$registration->uuid])->assertOk()->assertExactJson([
            'queued' => 1, 'skipped' => 0, 'no_email' => 0, 'pending' => 0, 'duplicate' => 0,
        ]);

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('event_registration_notifications', [
            'event_registration_id' => $registration->id,
            'status' => EventRegistrationNotification::STATUS_QUEUED,
            'sent_at' => null,
            'attempts' => 0,
        ]);
        $this->assertSame($history, $previous->fresh()->getAttributes());
        $this->assertDatabaseCount('event_registrations', 1);
        $this->assertSame($registrationAttributes, $registration->fresh()->getAttributes());
        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame($attendanceAttributes, $attendance->fresh()->getAttributes());
        $this->assertSame([$day->id], $registration->invitedDays()->get()->modelKeys());
        Mail::assertNothingSent();
    }

    public function test_bulk_resend_queues_both_participant_types_and_records_skips_across_chunks(): void
    {
        config(['event_notifications.batch_size' => 2]);
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->published()->create();
        $day = EventDay::factory()->for($event)->create();
        $employee = Employee::factory()->create(['email' => 'employee@example.test']);
        $external = ExternalParticipant::factory()->create(['email' => 'external@example.test']);
        $registeredEmployee = EventRegistration::factory()->for($event)->for($employee)->invitedTo($day)->create();
        $registeredExternal = EventRegistration::factory()->external()->for($event)->invitedTo($day)
            ->create(['external_participant_id' => $external->id]);
        $missingEmail = EventRegistration::factory()->for($event)->for(Employee::factory()->state(['email' => null]))->invitedTo($day)->create();
        $malformedEmail = EventRegistration::factory()->external()->for($event)->invitedTo($day)
            ->create(['external_participant_id' => ExternalParticipant::factory()->state(['email' => 'invalid-email'])]);
        $cancelled = EventRegistration::factory()->for($event)->cancelled()->create();
        $unassigned = EventRegistration::factory()->for($event)->for(Employee::factory()->state(['email' => 'no-day@example.test']))->create();

        $this->resend($event, [$registeredEmployee->uuid, $missingEmail->uuid, $registeredExternal->uuid,
            $malformedEmail->uuid, $cancelled->uuid, $unassigned->uuid, strtoupper($registeredEmployee->uuid)])
            ->assertOk()->assertExactJson(['queued' => 2, 'skipped' => 4, 'no_email' => 2, 'pending' => 0, 'duplicate' => 0]);

        $this->assertDatabaseCount('jobs', 2);
        foreach ([$registeredEmployee, $registeredExternal] as $registration) {
            $this->assertDatabaseHas('event_registration_notifications', [
                'event_registration_id' => $registration->id,
                'status' => EventRegistrationNotification::STATUS_QUEUED,
                'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
            ]);
        }
        foreach ([$missingEmail, $malformedEmail] as $registration) {
            $this->assertDatabaseHas('event_registration_notifications', [
                'event_registration_id' => $registration->id,
                'status' => EventRegistrationNotification::STATUS_SKIPPED,
                'last_error' => EventNotificationEligibility::MISSING_EMAIL,
                'recipient_email' => null,
            ]);
        }
        $this->assertTrue($unassigned->notifications()->sole()->isSkipped());
        $this->assertDatabaseMissing('event_registration_notifications', ['event_registration_id' => $cancelled->id]);
    }

    public function test_repeated_request_queues_once_and_a_new_confirmation_can_resend_again(): void
    {
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->published()->create();
        $registration = EventRegistration::factory()->for($event)
            ->for(Employee::factory()->state(['email' => 'employee@example.test']))->invitedTo()->create();
        $requestId = bin2hex(random_bytes(16));

        $this->resend($event, [$registration->uuid], $requestId)->assertOk()->assertJsonPath('queued', 1);
        $this->resend($event, [$registration->uuid], $requestId)->assertOk()->assertJsonPath('queued', 0)->assertJsonPath('duplicate', 1);

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('event_registration_notifications', 1);

        $this->resend($event, [$registration->uuid])->assertOk()->assertJsonPath('queued', 1);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_invalid_or_foreign_registration_ids_are_rejected_before_dispatching_any_chunk(): void
    {
        config(['event_notifications.batch_size' => 1]);
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->published()->create();
        $registration = EventRegistration::factory()->for($event)
            ->for(Employee::factory()->state(['email' => 'employee@example.test']))->invitedTo()->create();
        $foreign = EventRegistration::factory()->create();

        $this->resend($event, [])->assertUnprocessable()->assertJsonValidationErrors('registration_ids');
        $this->resend($event, ['invalid'])->assertUnprocessable()->assertJsonValidationErrors('registration_ids.0');
        $this->resend($event, [$registration->uuid, (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('registration_ids');
        $this->resend($event, [$registration->uuid, $foreign->uuid])->assertUnprocessable()->assertJsonValidationErrors('registration_ids');
        $this->resend($event, [$registration->uuid], 'invalid')->assertUnprocessable()->assertJsonValidationErrors('request_id');

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_guests_and_users_missing_either_event_permission_cannot_dispatch(): void
    {
        $event = Event::factory()->published()->create();
        $registration = EventRegistration::factory()->for($event)->create();

        $this->resend($event, [$registration->uuid])->assertUnauthorized();
        $this->actingAs($this->user('events.view'));
        $this->resend($event, [$registration->uuid])->assertForbidden();
        $this->get(route('events.show', $event))->assertOk()->assertDontSee('data-resend-participant', false);
        $this->actingAs($this->user('events.update'));
        $this->resend($event, [$registration->uuid])->assertForbidden();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_bulk_resend_requires_a_published_event_and_an_asynchronous_queue(): void
    {
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->create();
        $registration = EventRegistration::factory()->for($event)->invitedTo()->create();

        $this->resend($event, [$registration->uuid])->assertUnprocessable()->assertJsonValidationErrors('notification');
        $event->update(['status' => Event::STATUS_PUBLISHED]);
        config(['event_notifications.connection' => 'sync']);
        $this->resend($event, [$registration->uuid])->assertUnprocessable()->assertJsonValidationErrors('notification');

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_bulk_resend_rejects_requests_without_a_csrf_token(): void
    {
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->published()->create();
        $registration = EventRegistration::factory()->for($event)->create();
        $environment = $this->app->environment();
        $this->app->instance('env', 'production');

        try {
            $this->resend($event, [$registration->uuid])->assertStatus(419);
        } finally {
            $this->app->instance('env', $environment);
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_failed_queue_push_is_reported_as_pending_and_can_be_recovered(): void
    {
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->published()->create();
        $registration = EventRegistration::factory()->for($event)
            ->for(Employee::factory()->state(['email' => 'employee@example.test']))->invitedTo()->create();
        $databaseQueue = config('queue.connections.database');
        config([
            'event_notifications.connection' => 'unavailable',
            'queue.connections.unavailable' => array_replace($databaseQueue, ['table' => 'unavailable_jobs']),
        ]);

        $this->resend($event, [$registration->uuid])->assertOk()->assertJsonPath('queued', 0)->assertJsonPath('pending', 1);
        $notification = $registration->notifications()->sole();
        $this->assertTrue($notification->isPending());
        $this->assertDatabaseCount('jobs', 0);

        config(['event_notifications.connection' => 'database', 'queue.connections.unavailable' => $databaseQueue]);
        $this->artisan('notifications:recover', ['--no-interaction' => true])->assertSuccessful();
        $this->assertSame(EventRegistrationNotification::STATUS_QUEUED, $notification->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_worker_retry_uses_latest_event_content_and_the_original_registration_qr(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $this->actingAs($this->user('events.view', 'events.update'));
        $event = Event::factory()->published()->create(['title' => 'Old event title']);
        $day = EventDay::factory()->for($event)->create(['date' => '2027-02-01']);
        $otherDay = EventDay::factory()->for($event)->create(['day_number' => 2, 'date' => '2027-02-16']);
        $primary = EventPoster::factory()->for($event)->primary()->hasAttached($day, [], 'days')->create();
        $poster = EventPoster::factory()->for($event)->hasAttached($otherDay, [], 'days')->create(['sort_order' => 1]);
        $original = UploadedFile::fake()->image('original.jpg', 80, 90);
        Storage::disk('local')->put(EventPoster::variantPath($primary->file_path, 'invitation'), file_get_contents($original->getPathname()));
        $schedule = EventSchedule::factory()->for($day)->create(['title' => 'Old session title']);
        EventSchedule::factory()->for($day)->inactive()->create(['title' => 'Inactive session']);
        $external = ExternalParticipant::factory()->create(['name' => 'Sari <Peserta>', 'email' => 'external@example.test']);
        $registration = EventRegistration::factory()->external()->for($event)->invitedTo($day)
            ->create(['external_participant_id' => $external->id]);
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->ordered()->andReturnUsing(function (Email $message) use ($original): never {
            $attachments = collect($message->getAttachments())->keyBy(fn ($attachment): string => $attachment->getFilename());
            $this->assertSame(file_get_contents($original->getPathname()), $attachments['event-poster.jpg']->getBody());

            throw new UnexpectedResponseException('SMTP reply 451 4.7.1 retry later', 451);
        });
        $mail = new MailManager($this->app);
        $mail->mailer('smtp')->setSymfonyTransport($transport);
        Mail::swap($mail);

        $this->resend($event, [$registration->uuid])->assertOk()->assertJsonPath('queued', 1);
        $notification = $registration->notifications()->sole();
        $worker = ['connection' => 'database', '--queue' => 'emails', '--once' => true, '--sleep' => 0, '--no-interaction' => true];
        $this->artisan('queue:work', $worker)->assertSuccessful();
        $this->assertTrue($notification->refresh()->isFailed());
        $this->assertSame(1, $notification->attempts);
        $this->assertNull($notification->sent_at);

        $logo = UploadedFile::fake()->image('latest.png', 64, 32)->store('event-logos', 'local');
        $posterImage = UploadedFile::fake()->image('latest.jpg', 100, 180);
        $posterContent = file_get_contents($posterImage->getPathname());
        Storage::disk('local')->put(EventPoster::variantPath($poster->file_path, 'invitation'), $posterContent);
        $event->update(['title' => 'Updated event title', 'description' => 'Updated <description>', 'logo_path' => $logo,
            'location' => 'Updated hall', 'google_maps_url' => 'https://maps.google.com/?q=Updated', 'start_date' => '2027-02-14', 'end_date' => '2027-02-16']);
        $day->update(['date' => '2027-02-15', 'title' => 'Invited day',
            'start_time' => '08:00', 'end_time' => '17:00', 'dress_code' => 'Batik']);
        $schedule->update(['title' => 'Latest <session>', 'start_time' => '14:00:00', 'end_time' => '15:00:00']);
        $this->put(route('events.update', $event), [
            'title' => $event->title, 'description' => $event->description, 'location' => $event->location, 'google_maps_url' => $event->google_maps_url,
            'start_date' => '2027-02-14', 'end_date' => '2027-02-16', 'status' => Event::STATUS_PUBLISHED,
            'poster_assignments' => [
                $primary->id => ['scope' => 'days', 'days' => [$otherDay->id]],
                $poster->id => ['scope' => 'days', 'days' => [$day->id]],
            ],
        ])->assertSessionHasNoErrors();
        $transport->shouldReceive('send')->once()->ordered()->andReturnUsing(function (Email $message, Envelope $envelope) use ($logo, $posterContent, $registration): SentMessage {
            $html = $message->getHtmlBody();
            $this->assertSame('Undangan Event - Updated event title', $message->getSubject());
            foreach (['Updated event title', 'Updated &lt;description&gt;', 'Updated hall', 'https://maps.google.com/?q=Updated',
                'Jadwal Kehadiran', '15 Februari 2027 | 08.00–17.00 WIB', 'Batik',
                'Sari &lt;Peserta&gt;', $registration->registration_number] as $text) {
                $this->assertStringContainsString($text, $html);
            }
            $this->assertSame(1, substr_count($html, '15 Februari 2027'));
            foreach (['Old session title', 'Latest &lt;session&gt;', 'Inactive session', 'Invited day',
                '14/02/2027', '16/02/2027', 'Yth.', 'External', 'Employee'] as $text) {
                $this->assertStringNotContainsString($text, $html);
            }
            $attachments = collect($message->getAttachments())->keyBy(fn ($attachment): string => $attachment->getFilename());
            $this->assertSame(Storage::disk('local')->get($logo), $attachments['event-logo.png']->getBody());
            $this->assertSame($posterContent, $attachments['event-poster.jpg']->getBody());
            $qr = new QrReader($attachments['registration-'.$registration->uuid.'.png']->getBody(), QrReader::SOURCE_TYPE_BLOB);
            $this->assertSame('RA1:'.$registration->uuid, $qr->text(['TRY_HARDER' => true]));

            return new SentMessage($message, $envelope);
        });

        $this->travel(61)->seconds();
        $this->artisan('queue:work', $worker)->assertSuccessful();
        $this->assertTrue($notification->refresh()->isSent());
        $this->assertSame(2, $notification->attempts);
        $this->assertNotNull($notification->sent_at);
        $this->assertNull($notification->last_error);
        $this->assertDatabaseCount('jobs', 0);
    }

    /** @param list<string> $registrationIds */
    private function resend(Event $event, array $registrationIds, ?string $requestId = null): TestResponse
    {
        return $this->postJson(route('events.participants.invitations.resend', $event), [
            'registration_ids' => $registrationIds,
            'request_id' => $requestId ?? bin2hex(random_bytes(16)),
        ]);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
