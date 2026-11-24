<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class EventInvitationExportService
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    private const INVITED_DATES = 'activeInvitedDays:event_days.id,event_days.date';

    private const REGISTRATION_BATCH_SIZE = 50;

    private const MEBIBYTE = 1024 * 1024;

    public function __construct(
        private readonly RegistrationQrCodeService $qrCode,
        private readonly EventDayInvitationService $invitations,
    ) {}

    public function download(Event $event, EventRegistration $registration): Response
    {
        abort_unless((int) $registration->event_id === (int) $event->id, 404);

        if (! $registration->isRegistered()) {
            throw ValidationException::withMessages(['export' => 'Undangan hanya tersedia untuk registrasi yang masih aktif.']);
        }

        $registration->loadMissing($registration->isEmployee() ? 'employee:id,name' : 'externalParticipant:id,name');
        $invitedDates = $this->invitations->dateSummary($registration->load(self::INVITED_DATES));
        if ($invitedDates === null) {
            throw ValidationException::withMessages(['export' => 'Peserta belum memiliki hari undangan aktif.']);
        }

        $registrationNumber = Str::slug($registration->registration_number) ?: $registration->uuid;
        $filename = 'undangan-'.$this->eventSlug($event).'-'.$registrationNumber.'.pdf';

        return $this->withExportMemoryLimit($event, $registration->registration_type, fn (): Response => response(
            $this->render($event, $registration, $invitedDates, $this->logoDataUri($event)),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ...self::PRIVATE_HEADERS,
            ],
        ), $registration);
    }

    public function bulk(Event $event, string $participantType): StreamedResponse
    {
        return $this->withExportMemoryLimit($event, $participantType, fn (): StreamedResponse => $this->buildArchive($event, $participantType));
    }

    private function buildArchive(Event $event, string $participantType): StreamedResponse
    {
        $registrations = $event->registrations()->registered()->whereHas('activeInvitedDays')
            ->select(['id', 'event_id', 'uuid', 'registration_number', 'registration_type', 'employee_id', 'external_participant_id'])
            ->with(self::INVITED_DATES);
        if ($participantType !== EventRegistration::TYPE_EXTERNAL) {
            $registrations->with('employee:id,name');
        }
        if ($participantType !== EventRegistration::TYPE_EMPLOYEE) {
            $registrations->with('externalParticipant:id,name');
        }
        if ($participantType !== 'all') {
            $registrations->where('registration_type', $participantType);
        }

        $archive = tmpfile();
        if ($archive === false) {
            throw new RuntimeException('Gagal menyiapkan berkas sementara untuk ekspor undangan.');
        }

        $count = 0;
        $renderingRegistration = null;
        try {
            $eventSlug = $this->eventSlug($event);
            $logoImage = $this->logoDataUri($event);
            $zip = new ZipStream(outputStream: $archive, defaultCompressionMethod: CompressionMethod::STORE, sendHttpHeaders: false);

            foreach ($registrations->lazyById(self::REGISTRATION_BATCH_SIZE) as $registration) {
                $invitedDates = $this->invitations->dateSummary($registration);
                if ($invitedDates === null) {
                    continue;
                }

                $participantSlug = Str::substr(Str::slug($registration->participant_name ?? ''), 0, 80) ?: 'peserta';
                $filename = "undangan-{$eventSlug}-{$participantSlug}-{$registration->id}.pdf";
                $renderingRegistration = $registration;
                $pdf = $this->render($event, $registration, $invitedDates, $logoImage);
                $renderingRegistration = null;
                $zip->addFile($filename, $pdf);
                unset($pdf);
                $count++;
            }

            if ($count === 0) {
                throw ValidationException::withMessages(['export' => 'Tidak ada peserta dengan hari undangan aktif untuk filter ini.']);
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

            throw $exception instanceof ValidationException
                ? $exception
                : $this->exportFailure($exception, $event, $participantType, $renderingRegistration, $count);
        }
    }

    private function logoDataUri(Event $event): ?string
    {
        $logo = $event->logoImage();
        if ($logo === null) {
            return null;
        }

        $dimensions = @getimagesizefromstring($logo['contents']);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || ! in_array($dimensions[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            Log::warning('Invalid invitation logo image.', ['event_id' => $event->id]);

            return null;
        }

        [$width, $height, $type] = $dimensions;
        // The logo is displayed at up to 64 by 48 pt. Four pixels per point covers print resolution.
        $scale = min(1, 256 / $width, 192 / $height);
        if ($scale === 1) {
            return 'data:'.$dimensions['mime'].';base64,'.base64_encode($logo['contents']);
        }

        $image = @imagecreatefromstring($logo['contents']);
        if ($image === false) {
            Log::warning('Undecodable invitation logo image.', ['event_id' => $event->id]);

            return null;
        }

        $thumbnail = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        if ($thumbnail === false) {
            throw new RuntimeException('Gagal mengubah ukuran logo undangan.');
        }
        if ($type === IMAGETYPE_PNG) {
            imagesavealpha($thumbnail, true);
        }

        ob_start();
        try {
            $encoded = $type === IMAGETYPE_PNG ? imagepng($thumbnail) : imagejpeg($thumbnail, null, 90);
            if (! $encoded) {
                throw new RuntimeException('Gagal menyimpan logo undangan.');
            }

            return 'data:'.$dimensions['mime'].';base64,'.base64_encode(ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    private function render(Event $event, EventRegistration $registration, string $invitedDates, ?string $logoImage): string
    {
        try {
            return Pdf::loadView('events.partials.invitation-document', [
                'event' => $event,
                'registration' => $registration,
                'participantName' => $registration->participant_name,
                'invitedDates' => $invitedDates,
                'logoImage' => $logoImage,
                'qrImage' => 'data:image/png;base64,'.base64_encode($this->qrCode->generateInvitationPng($registration)),
            ])->setPaper('a5', 'portrait')->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
            ])->output();
        } finally {
            // DomPDF's frame and canvas references can outlive the local PDF wrapper until cycle collection.
            gc_collect_cycles();
        }
    }

    /**
     * @template TResponse of Response|StreamedResponse
     *
     * @param  Closure(): TResponse  $export
     * @return TResponse
     */
    private function withExportMemoryLimit(Event $event, string $participantType, Closure $export, ?EventRegistration $registration = null): Response|StreamedResponse
    {
        $previous = ini_get('memory_limit');
        $changed = false;

        try {
            $configured = min(512, max(1, (int) config('event_invitations.memory_limit_mb', 512))) * self::MEBIBYTE;
            $limit = $this->containerAwareMemoryLimit($configured);
            $previousBytes = ini_parse_quantity($previous);
            if ($previousBytes !== $limit) {
                if (memory_get_usage(true) > $limit) {
                    gc_collect_cycles();
                    gc_mem_caches();
                }
                $changed = @ini_set('memory_limit', (string) $limit) !== false;
                if (! $changed && ($previousBytes < 0 || $previousBytes > $limit)) {
                    throw new RuntimeException('Gagal menerapkan batas memori ekspor undangan.');
                }
            }

            return $export();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->exportFailure($exception, $event, $participantType, $registration, 0);
        } finally {
            if ($changed && @ini_set('memory_limit', $previous) === false) {
                gc_mem_caches();
                if (@ini_set('memory_limit', $previous) === false) {
                    Log::warning('Failed to restore PHP memory limit after invitation export.', ['event_id' => $event->id, 'memory_limit' => $previous]);
                }
            }
        }
    }

    private function containerAwareMemoryLimit(int $limit): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return $limit;
        }

        foreach (@file('/proc/self/cgroup', FILE_IGNORE_NEW_LINES) ?: [] as $membership) {
            [, $controllers, $group] = explode(':', $membership, 3);
            if ($controllers === '') {
                $root = '/sys/fs/cgroup';
                $filename = 'memory.max';
            } elseif (in_array('memory', explode(',', $controllers), true)) {
                $root = '/sys/fs/cgroup/memory';
                $filename = 'memory.limit_in_bytes';
            } else {
                continue;
            }

            for ($path = rtrim($root.$group, '/'); $path === $root || str_starts_with($path, $root.'/'); $path = dirname($path)) {
                $value = trim((string) @file_get_contents($path.'/'.$filename));
                if (ctype_digit($value) && (int) $value > 0) {
                    // Leave half of the hard limit for native allocations, FrankenPHP, and other requests.
                    $limit = min($limit, intdiv((int) $value, 2));
                }
            }
        }

        return $limit;
    }

    private function exportFailure(Throwable $exception, Event $event, string $participantType, ?EventRegistration $registration, int $completed): ValidationException
    {
        Context::scope(fn () => report($exception), [
            'event_id' => $event->id,
            'registration_id' => $registration?->id,
            'participant_type' => $participantType,
            'invitations_completed' => $completed,
            'php_memory_limit' => ini_get('memory_limit'),
            'php_memory_bytes' => memory_get_usage(true),
            'php_peak_memory_bytes' => memory_get_peak_usage(true),
        ]);

        return ValidationException::withMessages([
            'export' => $registration === null
                ? 'Ekspor undangan gagal dibuat. Silakan coba lagi.'
                : "Undangan untuk registrasi {$registration->registration_number} gagal dibuat.",
        ])->status(500);
    }

    private function eventSlug(Event $event): string
    {
        return Str::substr(Str::slug($event->title), 0, 80) ?: 'event-'.$event->uuid;
    }
}
