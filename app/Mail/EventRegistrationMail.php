<?php

namespace App\Mail;

use App\Models\EventRegistrationNotification;
use App\Services\EventInvitationArtwork;
use App\Services\EventInvitationPresentation;
use App\Services\RegistrationQrCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use LogicException;

class EventRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EventRegistrationNotification $notification, private bool $preview = false) {}

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
        return new Content(view: 'emails.event-registration', with: $this->emailData(includeInvitationDetails: true));
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
        $event = $registration?->event;
        if (! $event || app(EventInvitationPresentation::class)->invitedDays($event, $registration)->isEmpty()) {
            return [];
        }

        $qrService = app(RegistrationQrCodeService::class);

        return [Attachment::fromData(fn () => $qrService->generatePng($registration), $qrService->filename($registration))->withMime('image/png')];
    }

    /**
     * Prepare email data from existing registration.
     */
    private function emailData(bool $includeInvitationDetails = false): array
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
                'body_text' => null,
                'highlight' => null,
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

        $presentation = app(EventInvitationPresentation::class);
        $days = $includeInvitationDetails
            ? $presentation->invitedDays($event, $registration->load('activeInvitedDays'))
            : collect();
        $hasQrCode = $type !== EventRegistrationNotification::TYPE_CANCELLATION && $days->isNotEmpty();
        $artwork = app(EventInvitationArtwork::class);
        $posterImages = $hasQrCode
            ? $artwork->resolvePosters($event, $days->pluck('id')->all())->map($artwork->poster(...))->filter()->values()->all()
            : [];

        return array_merge($content, [
            'participant_name' => $participant->name,
            'registration_number' => $registration->registration_number,

            'event_title' => $event->title,
            'event_description' => $event->description,
            'event_location' => $event->location,
            'event_maps_url' => $event->google_maps_url,
            'attendance' => $presentation->attendance($event, $days),
            'event_logo' => $includeInvitationDetails ? $artwork->logo($event) : null,
            'event_posters' => $posterImages,
            'invite_preview' => $this->preview,
            'qr_preview' => $this->preview && $hasQrCode ? base64_encode(app(RegistrationQrCodeService::class)->generateInvitationPng($registration)) : null,
            'has_qr_code' => $hasQrCode,
        ]);
    }
}
