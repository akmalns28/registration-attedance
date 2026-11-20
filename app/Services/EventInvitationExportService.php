<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipStream\ZipStream;

class EventInvitationExportService
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly RegistrationQrCodeService $qrCode) {}

    public function download(Event $event, EventRegistration $registration): Response
    {
        abort_unless((int) $registration->event_id === (int) $event->id, 404);

        if (! $registration->isRegistered()) {
            throw ValidationException::withMessages(['export' => 'Undangan hanya tersedia untuk registrasi yang masih aktif.']);
        }

        $registration->loadMissing(['employee', 'externalParticipant', 'activeInvitedDays']);
        $registrationNumber = Str::slug($registration->registration_number) ?: $registration->uuid;
        $filename = 'undangan-'.$this->eventSlug($event).'-'.$registrationNumber.'.pdf';

        return response($this->render($event, $registration), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ...self::PRIVATE_HEADERS,
        ]);
    }

    public function bulk(Event $event, string $participantType): StreamedResponse
    {
        $registrations = $event->registrations()->registered()->with(['employee', 'externalParticipant', 'activeInvitedDays']);
        if ($participantType !== 'all') {
            $registrations->where('registration_type', $participantType);
        }

        $archive = tmpfile();
        if ($archive === false) {
            throw new RuntimeException('Gagal menyiapkan berkas sementara untuk ekspor undangan.');
        }

        $eventSlug = $this->eventSlug($event);
        try {
            $zip = new ZipStream(outputStream: $archive, sendHttpHeaders: false);
            $count = 0;

            foreach ($registrations->lazyById(50) as $registration) {
                $participantSlug = Str::substr(Str::slug($registration->participant_name ?? ''), 0, 80) ?: 'peserta';
                $filename = "undangan-{$eventSlug}-{$participantSlug}-{$registration->id}.pdf";
                $zip->addFile($filename, $this->render($event, $registration));
                $count++;
            }

            if ($count === 0) {
                throw ValidationException::withMessages(['export' => 'Tidak ada peserta aktif untuk filter undangan ini.']);
            }

            $size = $zip->finish();
            if (fstat($archive)['size'] !== $size || ! rewind($archive)) {
                throw new RuntimeException('Arsip undangan gagal disimpan secara lengkap.');
            }

            return response()->streamDownload(function () use ($archive): void {
                try {
                    fpassthru($archive);
                } finally {
                    fclose($archive);
                }
            }, $eventSlug.'-invitations.zip', [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) $size,
                ...self::PRIVATE_HEADERS,
            ]);
        } catch (Throwable $exception) {
            fclose($archive);

            throw $exception;
        }
    }

    private function render(Event $event, EventRegistration $registration): string
    {
        try {
            return Pdf::loadView('events.partials.invitation-document', [
                'event' => $event,
                'registration' => $registration,
                'participantName' => $registration->participant_name,
                'qrImage' => 'data:image/png;base64,'.base64_encode($this->qrCode->generatePng($registration)),
            ])->setPaper('a5', 'portrait')->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
            ])->output();
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'export' => "Undangan untuk registrasi {$registration->registration_number} gagal dibuat.",
            ])->status(500);
        }
    }

    private function eventSlug(Event $event): string
    {
        return Str::substr(Str::slug($event->title), 0, 80) ?: 'event-'.$event->uuid;
    }
}
