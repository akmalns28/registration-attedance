<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\EventRegistrationNotification;
use App\Services\EventDayInvitationService;
use App\Services\EventInvitationArtwork;
use App\Services\RegistrationQrCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

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

        if (! $registration || $registration->loadMissing('activeInvitedDays')->activeInvitedDays->isEmpty()) {
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

        $invitedDates = $includeInvitationDetails
            ? app(EventDayInvitationService::class)->dateSummary($registration->load('activeInvitedDays'))
            : null;
        $hasQrCode = $type !== EventRegistrationNotification::TYPE_CANCELLATION && $invitedDates !== null;
        $includeSchedule = $includeInvitationDetails && ($this->notification->metadata['bulk_resend'] ?? false);
        if ($includeSchedule) {
            $registration->loadMissing(['activeInvitedDays.schedules' => fn (HasMany $schedules): HasMany => $schedules->active()->ordered()]);
        }
        $posterImage = null;
        if ($hasQrCode) {
            $artwork = app(EventInvitationArtwork::class);
            $poster = $artwork->resolvePoster($event->load('posters.days'), $registration->activeInvitedDays->modelKeys());
            $posterImage = $poster === null ? null : $artwork->poster($poster);
        }

        return array_merge($content, [
            'participant_name' => $participant->name,
            'registration_number' => $registration->registration_number,

            'event_title' => $event->title,
            'event_description' => $event->description,
            'event_location' => $event->location,
            'event_maps_url' => $event->google_maps_url,
            'event_date' => $invitedDates,
            'event_schedule' => $includeSchedule ? $event->start_date->format('d/m/Y')
                .($event->start_date->isSameDay($event->end_date) ? '' : ' – '.$event->end_date->format('d/m/Y')) : null,
            'invited_rundown' => $includeSchedule
                ? $registration->activeInvitedDays->filter(fn ($day): bool => $day->schedules->isNotEmpty()) : [],
            'event_logo' => $includeInvitationDetails ? $this->eventLogo($event) : null,
            'event_poster' => $posterImage,
            'invite_preview' => $this->preview,
            'qr_preview' => $this->preview && $hasQrCode ? base64_encode(app(RegistrationQrCodeService::class)->generateInvitationPng($registration)) : null,
            'has_qr_code' => $hasQrCode,
        ]);
    }

    /** @return array{contents: string, mime: string}|null */
    private function eventLogo(Event $event): ?array
    {
        try {
            $logo = $event->logoImage();

            return empty($logo['contents']) ? null : $logo;
        } catch (Throwable $exception) {
            Log::warning('Event invitation logo could not be read.', [
                'event_id' => $event->id,
                'exception_type' => $exception::class,
            ]);

            return null;
        }
    }
}
