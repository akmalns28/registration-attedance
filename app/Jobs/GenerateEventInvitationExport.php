<?php

namespace App\Jobs;

use App\Models\EventInvitationExport;
use App\Services\EventInvitationExportQueue;
use App\Services\EventInvitationExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class GenerateEventInvitationExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public string $exportId, public int $batchNumber)
    {
        $this->timeout = max(30, (int) config('event_invitations.job_timeout'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(EventInvitationExportService $pdfs, EventInvitationExportQueue $queue): void
    {
        $started = hrtime(true);
        memory_reset_peak_usage();
        $export = DB::transaction(function (): ?EventInvitationExport {
            $export = $this->lockedCurrent();
            $export?->update(['status' => EventInvitationExport::PROCESSING]);

            return $export;
        }, attempts: 3);
        if ($export === null) {
            return;
        }

        $event = $export->event;
        $eligible = $pdfs->eligible($event, $export->participant_type)
            ->where('id', '>', $export->cursor)->where('id', '<=', $export->last_registration_id);
        $registrations = DB::transaction(fn () => $pdfs->withInvitationDetails($eligible, $export->participant_type)->limit($export->batch_size)->get());
        $lastId = $registrations->last()?->id;

        if ($lastId === null) {
            $this->package($export);

            return;
        }

        $registrations = $registrations->filter(fn ($registration): bool => $registration->activeInvitedDays->isNotEmpty());
        $pdf = $pdfs->render($event, $registrations);
        DB::transaction(function () use ($pdf, $lastId, $registrations, $queue): void {
            $current = $this->lockedCurrent();
            if ($current === null) {
                return;
            }
            if (! Storage::disk('local')->put($current->batchPath($current->batch_number), $pdf, 'private')) {
                throw new RuntimeException('Invitation export batch could not be stored.');
            }

            $current->update([
                'cursor' => $lastId,
                'processed_count' => $current->processed_count + $registrations->count(),
                'batch_number' => $current->batch_number + 1,
                'peak_memory_bytes' => max($current->peak_memory_bytes, memory_get_peak_usage(true)),
            ]);
            $queue->enqueue($current);
        }, attempts: 3);

        Log::debug('Invitation export batch processed.', [
            ...$this->context($export),
            'batch_number' => $this->batchNumber + 1,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);
    }

    private function package(EventInvitationExport $export): void
    {
        if ($export->processed_count === 0) {
            $this->fail(new RuntimeException('No eligible invitations remained.'));

            return;
        }

        $disk = Storage::disk('local');
        $pendingPath = $export->directory().'/invitations.tmp';
        $zip = new ZipArchive;
        if ($zip->open($disk->path($pendingPath), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Invitation ZIP could not be opened.');
        }

        try {
            for ($batch = 0; $batch < $export->batch_number; $batch++) {
                $path = $export->batchPath($batch);
                if (! $zip->addFile($disk->path($path), basename($path)) || ! $zip->setCompressionName(basename($path), ZipArchive::CM_STORE)) {
                    throw new RuntimeException('Invitation PDF could not be added to ZIP.');
                }
            }
            if ($zip->numFiles === 0 || ! $zip->close()) {
                throw new RuntimeException('Invitation ZIP could not be finalized.');
            }
        } catch (Throwable $exception) {
            unset($zip);
            $disk->delete($pendingPath);

            throw $exception;
        }

        $completed = DB::transaction(function () use ($disk, $pendingPath): ?EventInvitationExport {
            $current = $this->lockedCurrent();
            if ($current === null) {
                return null;
            }
            if (! $disk->move($pendingPath, $current->archivePath())) {
                throw new RuntimeException('Invitation ZIP could not be published.');
            }
            $current->update([
                'status' => EventInvitationExport::COMPLETED,
                'peak_memory_bytes' => max($current->peak_memory_bytes, memory_get_peak_usage(true)),
                'expires_at' => $this->expiration(),
            ]);

            return $current;
        });
        $disk->delete($pendingPath);

        if ($completed !== null) {
            $disk->deleteDirectory($completed->directory().'/batches');
            Log::info('Invitation export completed.', [
                ...$this->context($completed),
                'processed_count' => $completed->processed_count,
                'duration_seconds' => now()->diffInSeconds($completed->created_at, absolute: true),
                'peak_memory_bytes' => $completed->peak_memory_bytes,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $export = DB::transaction(function (): ?EventInvitationExport {
            $export = $this->lockedCurrent();
            $export?->update(['status' => EventInvitationExport::FAILED, 'expires_at' => $this->expiration()]);

            return $export;
        }, attempts: 3);

        if ($export !== null) {
            Log::warning('Invitation export failed.', [
                ...$this->context($export),
                'processed_count' => $export->processed_count,
                'batch_number' => $this->batchNumber + 1,
                'duration_seconds' => now()->diffInSeconds($export->created_at, absolute: true),
                'peak_memory_bytes' => max($export->peak_memory_bytes, memory_get_peak_usage(true)),
                'failure_reason' => $exception::class,
            ]);
        }
    }

    private function lockedCurrent(): ?EventInvitationExport
    {
        return EventInvitationExport::query()->whereKey($this->exportId)->where('batch_number', $this->batchNumber)
            ->whereIn('status', [EventInvitationExport::PENDING, EventInvitationExport::PROCESSING])
            ->lockForUpdate()->first();
    }

    private function expiration(): Carbon
    {
        return now()->addHours(max(1, (int) config('event_invitations.retention_hours')));
    }

    /** @return array{export_id: string, event_id: int, participant_count: int, batch_size: int} */
    private function context(EventInvitationExport $export): array
    {
        return [
            'export_id' => $export->id,
            'event_id' => $export->event_id,
            'participant_count' => $export->participant_count,
            'batch_size' => $export->batch_size,
        ];
    }
}
