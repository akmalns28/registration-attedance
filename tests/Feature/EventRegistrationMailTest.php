<?php

namespace Tests\Feature;

use App\Mail\EventRegistrationMail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\UnableToReadFile;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use Zxing\QrReader;

class EventRegistrationMailTest extends TestCase
{
    use RefreshDatabase;

    private function notification(string $type = EventRegistrationNotification::TYPE_INVITATION, array $attributes = []): EventRegistrationNotification
    {
        $registration = EventRegistration::factory()->invitedTo()->create();

        return EventRegistrationNotification::factory()->create(
            array_merge(
                [
                    'event_id' => $registration->event_id,
                    'event_registration_id' => $registration->id,
                    'notification_type' => $type,
                ],
                $attributes,
            ),
        );
    }

    public function test_invitation_email_can_be_rendered(): void
    {
        $applicationName = 'Event & Attendance <Portal>';
        config(['app.name' => $applicationName]);
        $notification = $this->notification();

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString('<title>Undangan Event - '.e($applicationName).'</title>', $html);
        $this->assertSame(3, substr_count($html, e($applicationName)));

        $this->assertStringContainsString($notification->registration->registration_number, $html);
    }

    public function test_cancellation_email_can_be_rendered(): void
    {
        $notification = $this->notification(EventRegistrationNotification::TYPE_CANCELLATION);

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString('Pembatalan Registrasi', $html);

        $this->assertStringContainsString('Cancelled', $html);
    }

    public function test_invitation_view_renders_with_legacy_data_that_omits_the_logo(): void
    {
        $mail = new EventRegistrationMail($this->notification());
        $content = $mail->content();
        $this->assertArrayHasKey('event_logo', $content->with);
        $this->assertNull($content->with['event_logo']);

        $html = view($content->view, array_diff_key($content->with, ['event_logo' => true]))->render();

        $this->assertStringContainsString(e(config('app.name')), $html);
        $this->assertStringContainsString('QR Code registrasi Anda telah dilampirkan', $html);
        $this->assertStringNotContainsString('src="cid:', $html);
    }

    public function test_an_unreadable_event_logo_does_not_prevent_rendering_or_the_qr_attachment(): void
    {
        $notification = $this->notification();
        $notification->registration->event->update(['logo_path' => 'event-logos/unreadable.png']);
        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('get')->once()->with('event-logos/unreadable.png')
            ->andThrow(UnableToReadFile::fromLocation('event-logos/unreadable.png'));
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $message = Mail::mailer('array')->to('recipient@example.test')->send(new EventRegistrationMail($notification))
            ->getSymfonySentMessage()->getOriginalMessage();

        $this->assertStringContainsString(e(config('app.name')), $message->getHtmlBody());
        $this->assertStringNotContainsString('src="cid:', $message->getHtmlBody());
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('RA1:'.$notification->registration->uuid, new QrReader($message->getAttachments()[0]->getBody(), QrReader::SOURCE_TYPE_BLOB)->text(['TRY_HARDER' => true]));
    }

    public function test_reactivation_email_can_be_rendered(): void
    {
        $notification = $this->notification(EventRegistrationNotification::TYPE_REACTIVATION);

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString('Registrasi Diaktifkan Kembali', $html);

        $this->assertStringContainsString('Registered', $html);
    }

    public function test_reminder_email_can_be_rendered(): void
    {
        $notification = $this->notification(EventRegistrationNotification::TYPE_REMINDER);

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString('Pengingat Pelaksanaan Event', $html);
    }

    public function test_email_contains_event_information(): void
    {
        $notification = $this->notification();

        $registration = $notification->registration;

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString(e($registration->event->title), $html);

        $this->assertStringContainsString(e($registration->event->location), $html);
        $this->assertStringNotContainsString('>'.e($registration->event->location).'</a>', $html);

        $this->assertStringContainsString($registration->registration_number, $html);
    }

    public function test_invitation_email_embeds_its_logo_and_uses_the_assigned_date_and_linked_location(): void
    {
        Storage::fake('local');
        $logoPath = UploadedFile::fake()->image('logo.png', 64, 42)->store('event-logos', 'local');
        $notification = $this->notification();
        $registration = $notification->registration;
        $registration->event->update(['start_date' => '2027-01-15', 'end_date' => '2027-01-17',
            'location' => 'Hotel <Kota>', 'google_maps_url' => 'https://maps.google.com/?q=Kota', 'logo_path' => $logoPath]);
        $registration->invitedDays()->firstOrFail()->update(['date' => '2027-01-16', 'title' => 'Hidden Day Title']);

        $message = Mail::mailer('array')->to('recipient@example.test')->send(new EventRegistrationMail($notification))
            ->getSymfonySentMessage()->getOriginalMessage();
        $html = $message->getHtmlBody();
        $this->assertStringContainsString('16 Januari 2027', $html);
        $this->assertStringNotContainsString('15 Januari 2027', $html);
        $this->assertStringNotContainsString('17 Januari 2027', $html);
        $this->assertStringContainsString('href="https://maps.google.com/?q=Kota"', $html);
        $this->assertStringContainsString('>Hotel &lt;Kota&gt;</a>', $html);
        $this->assertStringContainsString('Klik lokasi untuk membuka Google Maps', $html);
        $this->assertStringNotContainsString('Hidden Day Title', $html);
        $this->assertStringContainsString('src="cid:', $html);
        $this->assertCount(2, $message->getAttachments());
        $inline = collect($message->getAttachments())->first(fn ($attachment): bool => $attachment->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline');
        $this->assertNotNull($inline);
        $this->assertSame(Storage::disk('local')->get($logoPath), $inline->getBody());
    }

    public function test_rendering_refreshes_revoked_assignments_and_omits_the_hint_for_invalid_maps_links(): void
    {
        $notification = $this->notification();
        $registration = $notification->registration->load('activeInvitedDays');
        $day = $registration->activeInvitedDays->sole();
        $registration->invitedDays()->updateExistingPivot($day->id, ['revoked_at' => now()]);
        $registration->event->update(['location' => 'Hotel Kota', 'google_maps_url' => 'ftp://maps.google.com/']);

        $mail = new EventRegistrationMail($notification);
        $html = $mail->render();

        $this->assertStringContainsString('Belum ada tanggal undangan aktif.', $html);
        $this->assertStringNotContainsString($day->date->locale('id')->translatedFormat('j F Y'), $html);
        $this->assertStringNotContainsString('QR Code registrasi Anda telah dilampirkan', $html);
        $this->assertSame([], $mail->attachments());
        $this->assertStringContainsString('Hotel Kota', $html);
        $this->assertStringNotContainsString('ftp://maps.google.com/', $html);
        $this->assertStringNotContainsString('Klik lokasi untuk membuka Google Maps', $html);
    }

    public function test_email_contains_employee_name(): void
    {
        $notification = $this->notification();

        $registration = $notification->registration;

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString(e($registration->employee->name), $html);
    }

    public function test_email_contains_external_participant_name(): void
    {
        $registration = EventRegistration::factory()->external()->create();

        $notification = EventRegistrationNotification::factory()->create([
            'event_id' => $registration->event_id,
            'event_registration_id' => $registration->id,
            'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
        ]);

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString(e($registration->externalParticipant->name), $html);
    }

    public function test_email_subject_matches_notification_type(): void
    {
        $notification = $this->notification(EventRegistrationNotification::TYPE_INVITATION);

        $mail = new EventRegistrationMail($notification);

        $this->assertSame('Undangan Event - '.$notification->registration->event->title, $mail->envelope()->subject);
    }

    public function test_email_subject_changes_for_cancellation(): void
    {
        $notification = $this->notification(EventRegistrationNotification::TYPE_CANCELLATION);

        $mail = new EventRegistrationMail($notification);

        $this->assertSame('Pembatalan Registrasi - '.$notification->registration->event->title, $mail->envelope()->subject);
    }

    public function test_invalid_notification_type_is_rejected(): void
    {
        $notification = $this->notification();

        $notification->notification_type = 'invalid-type';

        $mail = new EventRegistrationMail($notification);

        $this->expectException(InvalidArgumentException::class);

        $mail->render();
    }

    public function test_notification_event_mismatch_is_rejected(): void
    {
        $notification = $this->notification();

        $otherEvent = Event::factory()->create();

        $notification->event_id = $otherEvent->id;

        $mail = new EventRegistrationMail($notification);

        $this->expectException(LogicException::class);

        $mail->render();
    }

    public function test_invitation_email_contains_qr_instructions(): void
    {
        $notification = $this->notification(EventRegistrationNotification::TYPE_INVITATION);

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString('QR Code registrasi Anda telah dilampirkan', $html);
        $this->assertStringContainsString('Pemindaian QR Code dilakukan oleh panitia', $html);
    }

    #[TestWith([EventRegistrationNotification::TYPE_INVITATION])]
    #[TestWith([EventRegistrationNotification::TYPE_REACTIVATION])]
    #[TestWith([EventRegistrationNotification::TYPE_REMINDER])]
    public function test_email_attaches_decodable_registration_token_png(string $type): void
    {
        $notification = $this->notification($type);

        $sent = Mail::mailer('array')->to('recipient@example.test')->send(new EventRegistrationMail($notification));

        $this->assertNotNull($sent);

        $attachments = $sent->getSymfonySentMessage()->getOriginalMessage()->getAttachments();

        $this->assertCount(1, $attachments);

        $this->assertSame('image/png', $attachments[0]->getContentType());

        $this->assertSame('registration-'.$notification->registration->uuid.'.png', $attachments[0]->getFilename());

        $this->assertSame('RA1:'.$notification->registration->uuid, new QrReader($attachments[0]->getBody(), QrReader::SOURCE_TYPE_BLOB)->text(['TRY_HARDER' => true]));
    }

    public function test_cancellation_email_is_sent_without_qr_attachment_or_instructions(): void
    {
        $notification = $this->notification(EventRegistrationNotification::TYPE_CANCELLATION);

        $sent = Mail::mailer('array')->to('recipient@example.test')->send(new EventRegistrationMail($notification));

        $this->assertNotNull($sent);
        $message = $sent->getSymfonySentMessage()->getOriginalMessage();
        $this->assertCount(0, $message->getAttachments());
        $this->assertStringNotContainsString('QR Code registrasi Anda telah dilampirkan', $message->getHtmlBody());
    }
}
