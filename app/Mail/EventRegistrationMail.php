<?php

namespace App\Mail;

use App\Models\EventRegistrationNotification;
use App\Services\RegistrationQrCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

class EventRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EventRegistrationNotification $notification) {}

    /**
     * Email subject and sender configuration.
     */
    public function envelope(): Envelope
    {
        $data = $this->emailData();

        return new Envelope(subject: $data['subject']);
    }

    /**
     * Email content.
     */
    public function content(): Content
    {
        return new Content(view: 'emails.event-registration', with: $this->emailData());
    }

    /**
     * Attach the registration QR Code PNG for event staff to scan at check-in.
     */
    public function attachments(): array
    {
        $allowedTypes = [EventRegistrationNotification::TYPE_INVITATION, EventRegistrationNotification::TYPE_REACTIVATION, EventRegistrationNotification::TYPE_REMINDER];

        if (! in_array($this->notification->notification_type, $allowedTypes, true)) {
            return [];
        }

        $registration = $this->notification->registration;

        if (! $registration) {
            return [];
        }

        $qrService = app(RegistrationQrCodeService::class);

        return [Attachment::fromData(fn () => $qrService->generatePng($registration), $qrService->filename($registration))->withMime('image/png')];
    }

    /**
     * Prepare email data from existing registration.
     */
    private function emailData(): array
    {
        $this->notification->loadMissing(['registration.employee', 'registration.externalParticipant', 'registration.event']);

        $registration = $this->notification->registration;

        if (! $registration) {
            throw new LogicException('Registrasi untuk notifikasi tidak ditemukan.');
        }

        $event = $registration->event;

        if (! $event) {
            throw new LogicException('Event untuk registrasi tidak ditemukan.');
        }

        if ((int) $this->notification->event_id !== (int) $registration->event_id) {
            throw new LogicException('Event notifikasi tidak sesuai dengan registrasi.');
        }

        $participant = $registration->isEmployee() ? $registration->employee : $registration->externalParticipant;

        if (! $participant) {
            throw new LogicException('Data peserta tidak ditemukan.');
        }

        $type = $this->notification->notification_type;

        $content = match ($type) {
            EventRegistrationNotification::TYPE_INVITATION => [
                'subject' => 'Undangan Event - '.$event->title,
                'heading' => 'Undangan Event',
                'body_text' => 'Anda telah terdaftar sebagai peserta pada event berikut.',
                'highlight' => 'Registrasi Anda telah tercatat.',
                'badge' => 'INVITATION',
                'badge_color' => '#2563eb',
            ],

            EventRegistrationNotification::TYPE_CANCELLATION => [
                'subject' => 'Pembatalan Registrasi - '.$event->title,
                'heading' => 'Pembatalan Registrasi',
                'body_text' => 'Kami informasikan bahwa registrasi Anda pada event berikut telah dibatalkan.',
                'highlight' => 'Registrasi Anda berstatus Cancelled.',
                'badge' => 'CANCELLATION',
                'badge_color' => '#dc2626',
            ],

            EventRegistrationNotification::TYPE_REACTIVATION => [
                'subject' => 'Konfirmasi Reaktivasi - '.$event->title,
                'heading' => 'Registrasi Diaktifkan Kembali',
                'body_text' => 'Registrasi Anda pada event berikut telah berhasil diaktifkan kembali.',
                'highlight' => 'Registrasi Anda kembali berstatus Registered.',
                'badge' => 'REACTIVATION',
                'badge_color' => '#16a34a',
            ],

            EventRegistrationNotification::TYPE_REMINDER => [
                'subject' => 'Pengingat Event - '.$event->title,
                'heading' => 'Pengingat Pelaksanaan Event',
                'body_text' => 'Kami mengingatkan Anda mengenai jadwal pelaksanaan event berikut.',
                'highlight' => 'Pastikan Anda mempersiapkan diri untuk mengikuti event.',
                'badge' => 'REMINDER',
                'badge_color' => '#d97706',
            ],

            default => throw new InvalidArgumentException('Jenis notifikasi email tidak valid.'),
        };

        $startDate = $event->start_date ? Carbon::parse($event->start_date)->format('d/m/Y') : '-';

        $endDate = $event->end_date ? Carbon::parse($event->end_date)->format('d/m/Y') : '-';

        $eventDate = $startDate === $endDate ? $startDate : "{$startDate} - {$endDate}";

        return array_merge($content, [
            'participant_name' => $participant->name,
            'participant_type' => $registration->isEmployee() ? 'Employee' : 'External Participant',

            'registration_number' => $registration->registration_number,

            'event_title' => $event->title,
            'event_description' => $event->description,
            'event_location' => $event->location ?: '-',
            'event_date' => $eventDate,

            'notification_type' => $type,
        ]);
    }
}
