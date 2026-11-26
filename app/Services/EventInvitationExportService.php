<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventPoster;
use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EventInvitationExportService
{
    private const INVITED_DATES = 'activeInvitedDays:event_days.id,event_days.date';

    public function __construct(
        private readonly RegistrationQrCodeService $qrCode,
        private readonly EventDayInvitationService $invitations,
        private readonly EventInvitationArtwork $artwork,
    ) {}

    public function download(Event $event, EventRegistration $registration): Response
    {
        abort_unless((int) $registration->event_id === (int) $event->id, 404);

        if (! $registration->isRegistered()) {
            throw ValidationException::withMessages(['export' => 'Undangan hanya tersedia untuk registrasi yang masih aktif.']);
        }

        $registration->loadMissing([$registration->isEmployee() ? 'employee:id,name' : 'externalParticipant:id,name', self::INVITED_DATES]);
        if ($registration->activeInvitedDays->isEmpty()) {
            throw ValidationException::withMessages(['export' => 'Peserta belum memiliki hari undangan aktif.']);
        }

        $number = Str::slug($registration->registration_number) ?: $registration->uuid;

        return $this->downloadPdf($event, collect([$registration]), 'undangan-'.$this->eventSlug($event).'-'.$number.'.pdf');
    }

    /** @return Builder<EventRegistration> */
    public function eligible(Event $event, string $participantType): Builder
    {
        return EventRegistration::query()->whereBelongsTo($event)->registered()->whereHas('activeInvitedDays')
            ->when($participantType !== 'all', fn (Builder $query): Builder => $query->where('registration_type', $participantType));
    }

    /** @param Builder<EventRegistration> $query */
    public function withInvitationDetails(Builder $query, string $participantType): Builder
    {
        return $query
            ->select(['id', 'event_id', 'uuid', 'registration_number', 'employee_id', 'external_participant_id', 'registration_type'])
            ->with(self::INVITED_DATES)
            ->when($participantType !== EventRegistration::TYPE_EXTERNAL, fn (Builder $query): Builder => $query->with('employee:id,name'))
            ->when($participantType !== EventRegistration::TYPE_EMPLOYEE, fn (Builder $query): Builder => $query->with('externalParticipant:id,name'))
            ->orderBy('id');
    }

    /** @param Collection<int, EventRegistration> $registrations */
    public function downloadPdf(Event $event, Collection $registrations, ?string $filename = null): Response
    {
        $started = hrtime(true);
        try {
            $pdf = $this->render($event, $registrations);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Context::scope(fn () => report($exception), [
                'event_id' => $event->id,
                'participant_count' => $registrations->count(),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ]);

            throw ValidationException::withMessages(['export' => 'Ekspor undangan gagal dibuat. Silakan coba lagi.'])->status(500);
        }

        Log::info('Invitation PDF rendered.', [
            'event_id' => $event->id,
            'participant_count' => $registrations->count(),
            'batch_size' => $registrations->count(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);

        return response($pdf, 200, [
            ...EventAssetStorage::PRIVATE_HEADERS,
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.($filename ?? $this->eventSlug($event).'-invitations.pdf').'"',
        ]);
    }

    /** @param Collection<int, EventRegistration> $registrations */
    public function render(Event $event, Collection $registrations): string
    {
        gc_collect_cycles();
        gc_mem_caches();
        $originalLimit = ini_get('memory_limit');
        $originalBytes = ini_parse_quantity($originalLimit);
        $configuredBytes = max(0, (int) config('event_invitations.memory_limit_mb')) * 1048576;
        $limited = $configuredBytes > memory_get_usage(true)
            && ($originalBytes < 0 || $configuredBytes < $originalBytes)
            && @ini_set('memory_limit', (string) $configuredBytes) !== false;

        try {
            $pages = [];
            $posterImages = [];
            foreach ($registrations as $registration) {
                $invitedDates = $this->invitations->dateSummary($registration);
                if ($invitedDates !== null) {
                    $poster = $this->artwork->resolvePoster($event, $registration->activeInvitedDays->modelKeys());
                    if ($poster !== null && ! array_key_exists($poster->id, $posterImages)) {
                        $posterImages[$poster->id] = $this->posterForPage($poster);
                    }

                    $pages[] = [
                        'registration' => $registration,
                        'participantName' => $registration->participant_name ?? 'Peserta',
                        'invitedDates' => $invitedDates,
                        'qrImage' => 'data:image/png;base64,'.base64_encode($this->qrCode->generateInvitationPng($registration)),
                        'poster' => $posterImages[$poster?->id] ?? null,
                    ];
                }
            }
            if ($pages === []) {
                throw ValidationException::withMessages(['export' => 'Tidak ada peserta dengan hari undangan aktif untuk filter ini.']);
            }

            $description = trim($event->description ?? '');
            $pdf = Pdf::loadView('events.partials.invitation-document', [
                'event' => $event,
                'pages' => $pages,
                'logoImage' => $this->artwork->dataUri($this->artwork->logo($event)),
                'description' => $description === '' ? [] : (preg_split('/\R/u', $description) ?: [$description]),
            ])->setPaper('a5', 'portrait')->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'isFontSubsettingEnabled' => true,
                'chroot' => [base_path(), Storage::disk('local')->path('event-posters')],
            ]);

            return $pdf->output();
        } finally {
            unset($pdf);
            gc_collect_cycles();
            gc_mem_caches();
            if ($limited) {
                ini_set('memory_limit', $originalLimit);
            }
        }
    }

    /** @return array{image: string, width: float, height: float}|null */
    private function posterForPage(EventPoster $poster): ?array
    {
        $image = $this->artwork->poster($poster);
        if ($image === null) {
            return null;
        }

        $scale = min(0.6, 245 / $image['width'], 120 / $image['height']);

        return [
            'image' => $image['file'] ?? $this->artwork->dataUri($image),
            'width' => round($image['width'] * $scale, 2),
            'height' => round($image['height'] * $scale, 2),
        ];
    }

    public function eventSlug(Event $event): string
    {
        return Str::substr(Str::slug($event->title), 0, 80) ?: 'event-'.$event->uuid;
    }
}
