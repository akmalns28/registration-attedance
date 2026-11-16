<?php

namespace Tests\Feature;

use App\Mail\EventRegistrationMail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use Zxing\QrReader;

class EventRegistrationMailTest extends TestCase
{
    use RefreshDatabase;

    private function notification(string $type = EventRegistrationNotification::TYPE_INVITATION, array $attributes = []): EventRegistrationNotification
    {
        $registration = EventRegistration::factory()->create();

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
        $notification = $this->notification();

        $mail = new EventRegistrationMail($notification);

        $html = $mail->render();

        $this->assertStringContainsString('Undangan Event', $html);

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

        $this->assertStringContainsString($registration->registration_number, $html);
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
