<?php

namespace App\Services;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Models\EventRegistrationNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventNotificationQueue
{
    /**
     * Stage delivery inside the caller's transaction; only dispatch after commit.
     */
    public function enqueue(EventRegistrationNotification $notification): void
    {
        $metadata = $notification->metadata ?? [];
        $generation = (int) ($metadata['dispatch_generation'] ?? 0) + 1;
        $connection = config('event_notifications.connection') ?: config('queue.default');
        $queue = config('event_notifications.queue', 'emails');
        $metadata['dispatch_generation'] = $generation;
        $metadata['queue_connection'] = $connection;
        $metadata['queue_name'] = $queue;
        unset($metadata['queue_job_id'], $metadata['processing_token']);

        $notification->update([
            'status' => EventRegistrationNotification::STATUS_QUEUED,
            'queued_at' => now(),
            'metadata' => $metadata,
        ]);

        $notificationId = (int) $notification->id;

        DB::afterCommit(function () use ($notificationId, $generation, $connection, $queue): void {
            try {
                /** The callback already runs after commit; avoid deferring twice and losing the queue job ID. */
                $job = (new SendEventRegistrationNotificationJob($notificationId, $generation))
                    ->onConnection($connection)->onQueue($queue)->beforeCommit();
                $jobId = Bus::dispatch($job);

                if (is_int($jobId) || is_string($jobId)) {
                    $this->generationQuery($notificationId, $generation)
                        ->where('status', EventRegistrationNotification::STATUS_QUEUED)
                        ->update(['metadata->queue_job_id' => (string) $jobId]);
                }
            } catch (Throwable $exception) {
                $this->generationQuery($notificationId, $generation)
                    ->where('status', EventRegistrationNotification::STATUS_QUEUED)
                    ->update([
                        'status' => EventRegistrationNotification::STATUS_PENDING,
                        'queued_at' => null,
                        'last_error' => 'Queue dispatch failed: '.$exception::class,
                    ]);

                Log::warning('Event notification queue dispatch failed; recovery is required.', [
                    'notification_id' => $notificationId,
                    'exception_type' => $exception::class,
                ]);
            }
        });
    }

    public function retryDelay(int $attempts): int
    {
        $backoff = config('event_notifications.backoff', [60, 300, 900]);

        return max(0, (int) ($backoff[min(max(0, $attempts - 1), count($backoff) - 1)] ?? 60));
    }

    public function leaseSeconds(?string $connection = null): int
    {
        $connection ??= config('event_notifications.connection') ?: config('queue.default');

        return max(
            (int) config('event_notifications.processing_lease', 180),
            (int) config('event_notifications.timeout', 60) + 30,
            (int) config('queue.connections.'.$connection.'.retry_after', 90) + 30,
        );
    }

    /**
     * A null result means the backend cannot prove that delivery is abandoned.
     * Existing database jobs, including delayed or expired reservations, are
     * retried by Laravel itself and must not be duplicated by recovery.
     */
    public function hasDatabaseJob(EventRegistrationNotification $notification): ?bool
    {
        $metadata = $notification->metadata ?? [];
        $connection = $metadata['queue_connection'] ?? (config('event_notifications.connection') ?: config('queue.default'));
        $configuration = config('queue.connections.'.$connection, []);

        if (($configuration['driver'] ?? null) !== 'database') {
            return null;
        }

        $query = DB::connection($configuration['connection'] ?? null)
            ->table($configuration['table'] ?? 'jobs')
            ->where('queue', $metadata['queue_name'] ?? config('event_notifications.queue', 'emails'))
            ->where('payload->displayName', SendEventRegistrationNotificationJob::class);
        $generation = (int) ($metadata['dispatch_generation'] ?? 0);
        $query->where('payload->data->command', 'like', '%s:14:"notificationId";i:'.$notification->id.';%')
            ->where(function ($query) use ($generation): void {
                $query->where('payload->data->command', 'like', '%s:18:"dispatchGeneration";i:'.$generation.';%');

                if ($generation === 0) {
                    $query->orWhere('payload->data->command', 'like', '%s:18:"dispatchGeneration";N;%')
                        ->orWhere('payload->data->command', 'not like', '%s:18:"dispatchGeneration";%');
                }
            });

        if (isset($metadata['queue_job_id']) && (clone $query)->where('id', $metadata['queue_job_id'])->exists()) {
            return true;
        }

        return $query->exists();
    }

    private function generationQuery(int $notificationId, int $generation): Builder
    {
        return EventRegistrationNotification::query()->whereKey($notificationId)->where('metadata->dispatch_generation', $generation);
    }
}
