<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Adapter\CPDF;
use Dompdf\Image\Cache as DompdfImageCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EventInvitationExportService
{
    private const INVITED_DAYS = 'activeInvitedDays:event_days.id,event_days.event_id,event_days.date,event_days.start_time,event_days.end_time,event_days.dress_code';

    public function __construct(
        private readonly RegistrationQrCodeService $qrCode,
        private readonly EventInvitationPresentation $presentation,
        private readonly EventInvitationArtwork $artwork,
    ) {}

    public function download(Event $event, EventRegistration $registration): Response
    {
        abort_unless((int) $registration->event_id === (int) $event->id, 404);

        if (! $registration->isRegistered()) {
            throw ValidationException::withMessages(['export' => 'Undangan hanya tersedia untuk registrasi yang masih aktif.']);
        }

        $registration->loadMissing([$registration->isEmployee() ? 'employee:id,name' : 'externalParticipant:id,name', self::INVITED_DAYS]);
        if ($registration->participant_name === null) {
            throw ValidationException::withMessages(['export' => 'Data peserta untuk undangan tidak ditemukan.']);
        }

        if ($this->presentation->invitedDays($event, $registration)->isEmpty()) {
            throw ValidationException::withMessages(['export' => 'Peserta belum memiliki hari undangan aktif.']);
        }

        $number = Str::slug($registration->registration_number) ?: $registration->uuid;

        return $this->downloadPdf($event, collect([$registration]), 'undangan-'.$this->eventSlug($event).'-'.$number.'.pdf');
    }

    /** @return Builder<EventRegistration> */
    public function eligible(Event $event, string $participantType): Builder
    {
        return EventRegistration::query()->whereBelongsTo($event)->registered()
            ->whereHas('activeInvitedDays', fn (Builder $days): Builder => $days->where('event_days.event_id', $event->id))
            ->where(function (Builder $query) use ($participantType): void {
                if ($participantType !== EventRegistration::TYPE_EXTERNAL) {
                    $query->where(fn (Builder $employees): Builder => $employees->employees()->whereHas('employee'));
                }
                if ($participantType !== EventRegistration::TYPE_EMPLOYEE) {
                    $query->orWhere(fn (Builder $externals): Builder => $externals->externals()->whereHas('externalParticipant'));
                }
            });
    }

    /** @param Builder<EventRegistration> $query */
    public function withInvitationDetails(Builder $query, string $participantType): Builder
    {
        return $query
            ->select(['id', 'event_id', 'uuid', 'registration_number', 'employee_id', 'external_participant_id', 'registration_type'])
            ->with(self::INVITED_DAYS)
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
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
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
            $logoImage = $this->artwork->dataUri($this->artwork->logo($event));
            foreach ($registrations as $registration) {
                $days = $this->presentation->invitedDays($event, $registration);
                if ($registration->participant_name !== null && $days->isNotEmpty()) {
                    $visibleImages = [];
                    foreach ($this->artwork->resolvePosters($event, $days->pluck('id')->all()) as $poster) {
                        if (! array_key_exists($poster->id, $posterImages)) {
                            $image = $this->artwork->poster($poster);
                            $posterImages[$poster->id] = $image === null ? null : [
                                'image' => $image['file'] ?? $this->artwork->dataUri($image),
                                'width' => $image['width'],
                                'height' => $image['height'],
                            ];
                        }
                        if ($posterImages[$poster->id] !== null) {
                            $visibleImages[] = $posterImages[$poster->id];
                        }
                    }

                    $pages[] = [
                        'registration' => $registration,
                        'participantName' => $registration->participant_name,
                        'attendance' => $this->presentation->attendance($event, $days),
                        'qrImage' => 'data:image/png;base64,'.base64_encode($this->qrCode->generateInvitationPng($registration)),
                        'posterGrid' => $this->posterGrid($visibleImages),
                    ];
                }
            }
            if ($pages === []) {
                throw ValidationException::withMessages(['export' => 'Tidak ada peserta dengan hari undangan aktif untuk filter ini.']);
            }

            $description = trim($event->description ?? '');
            $html = view('events.partials.invitation-document', [
                'event' => $event,
                'pages' => $pages,
                'logoImage' => $logoImage,
                'description' => $description === '' ? [] : (preg_split('/\R/u', $description) ?: [$description]),
            ])->render();

            return $this->renderDocument($html, count($pages));
        } finally {
            DompdfImageCache::clear();
            unset($html, $pages, $posterImages, $logoImage);
            gc_collect_cycles();
            gc_mem_caches();
            if ($limited) {
                ini_set('memory_limit', $originalLimit);
            }
        }
    }

    private function renderDocument(string $html, int $invitationCount): string
    {
        $paper = CPDF::$PAPER_SIZES['a5'];
        $options = [
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'chroot' => [base_path(), Storage::disk('local')->path('event-posters')],
        ];

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $pdf = Pdf::loadHTML($html)->setPaper($paper)->setOptions($options, mergeWithDefaults: true);
            $pdf->render();
            $pageCount = $pdf->getDomPDF()->getCanvas()->get_page_count();
            if ($pageCount === $invitationCount) {
                return $pdf->output();
            }

            // Give long event details more space while keeping the QR and text at their original sizes.
            $paper[3] *= max(1.25, $pageCount / $invitationCount);
            unset($pdf);
            DompdfImageCache::clear();
            gc_collect_cycles();
        }

        throw new RuntimeException('Could not fit each invitation on a single PDF page.');
    }

    /**
     * @param  list<array{image: string, width: int, height: int}>  $images
     * @return array{cellWidth: float, rows: list<list<array{image: string, width: float, height: float}>>}
     */
    private function posterGrid(array $images): array
    {
        $columns = max(1, (int) ceil(sqrt(count($images))));
        $rows = array_chunk($images, $columns);
        $cellWidth = 350 / $columns;
        $maxWidth = min(245, $cellWidth - 6);
        $maxHeight = min(105, 130 / max(1, count($rows)) - 4);

        return [
            'cellWidth' => $cellWidth,
            'rows' => array_map(fn (array $row): array => array_map(function (array $image) use ($maxWidth, $maxHeight): array {
                $scale = min(0.6, $maxWidth / $image['width'], $maxHeight / $image['height']);

                return [
                    'image' => $image['image'],
                    'width' => round($image['width'] * $scale, 2),
                    'height' => round($image['height'] * $scale, 2),
                ];
            }, $row), $rows),
        ];
    }

    public function eventSlug(Event $event): string
    {
        return Str::substr(Str::slug($event->title), 0, 80) ?: 'event-'.$event->uuid;
    }
}
