<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class EventNotificationRecoveryService
{
    public function __construct(
        private readonly EventNotificationEligibility $eligibility,
        private readonly EventNotificationQueue $queue,
    ) {}

    /**
     * The limit bounds scheduled deliveries; iteration always uses bounded chunks.
     *
     * @return array{checked: int, eligible: int, recovered: int, skipped: int, ineligible: int, exhausted: int, errors: int}
     */
    public function recover(bool $dryRun = false, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('The recovery limit must be between 1 and 10000.');
        }

        $counts = ['checked' => 0, 'eligible' => 0, 'recovered' => 0, 'skipped' => 0, 'ineligible' => 0, 'exhausted' => 0, 'errors' => 0];

        EventRegistrationNotification::query()->whereIn('status', [
            EventRegistrationNotification::STATUS_PENDING,
            EventRegistrationNotification::STATUS_QUEUED,
            EventRegistrationNotification::STATUS_PROCESSING,
            EventRegistrationNotification::STATUS_FAILED,
        ])->select(['id', 'event_id', 'event_registration_id'])
            ->chunkById(max(1, min(500, (int) config('event_notifications.batch_size', 100))), function ($notifications) use ($dryRun, $limit, &$counts): bool {
                foreach ($notifications as $snapshot) {
                    $counts['checked']++;

                    try {
                        $outcome = $this->recoverOne($snapshot, $dryRun);
                    } catch (Throwable $exception) {
                        $outcome = 'errors';
                        Log::warning('Event notification recovery failed.', [
                            'notification_id' => $snapshot->id,
                            'exception_type' => $exception::class,
                        ]);
                    }

                    if (in_array($outcome, ['eligible', 'recovered', 'dispatch_failed'], true)) {
                        $counts['eligible']++;
                    }

                    if ($outcome === 'dispatch_failed') {
                        $counts['errors']++;
                    } elseif ($outcome !== 'eligible') {
                        $counts[$outcome]++;
                    }

                    if ($counts['eligible'] >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $counts;
    }

    private function recoverOne(EventRegistrationNotification $snapshot, bool $dryRun): string
    {
        $outcome = DB::transaction(function () use ($snapshot, $dryRun): string {
            Event::query()->whereKey($snapshot->event_id)->lockForUpdate()->first();
            EventRegistration::query()->whereKey($snapshot->event_registration_id)->lockForUpdate()->first();
            $notification = EventRegistrationNotification::query()
                ->with(['registration.event', 'registration.employee', 'registration.externalParticipant'])
                ->whereKey($snapshot->id)->lockForUpdate()->first();

            if ($notification === null || ! $this->isDue($notification)) {
                return 'skipped';
            }

            if ($this->queue->hasDatabaseJob($notification) !== false) {
                return 'skipped';
            }

            if ($notification->attempts >= max(1, (int) config('event_notifications.tries', 3))) {
                if (! $dryRun && $notification->status === EventRegistrationNotification::STATUS_PROCESSING) {
                    $notification->update([
                        'status' => EventRegistrationNotification::STATUS_FAILED,
                        'failed_at' => $notification->failed_at ?? now(),
                        'last_error' => $notification->last_error ?? EventNotificationFailure::RETRY_LIMIT,
                    ]);
                }

                return 'exhausted';
            }

            if ($reason = $this->eligibility->reason($notification)) {
                if (! $dryRun) {
                    $notification->update(['status' => EventRegistrationNotification::STATUS_SKIPPED, 'last_error' => $reason]);
                }

                return 'ineligible';
            }

            if ($dryRun) {
                return 'eligible';
            }

            $this->queue->enqueue($notification);

            return 'recovered';
        }, attempts: 3);

        if ($outcome === 'recovered' && EventRegistrationNotification::query()->whereKey($snapshot->id)->value('status') === EventRegistrationNotification::STATUS_PENDING) {
            return 'dispatch_failed';
        }

        return $outcome;
    }

    private function isDue(EventRegistrationNotification $notification): bool
    {
        if ($notification->scheduled_for?->isFuture()) {
            return false;
        }

        return match ($notification->status) {
            EventRegistrationNotification::STATUS_PENDING => true,
            EventRegistrationNotification::STATUS_QUEUED => ($notification->queued_at ?? $notification->updated_at)
                ->lte(now()->subSeconds(max(1, (int) config('event_notifications.stale_after', 300)))),
            EventRegistrationNotification::STATUS_PROCESSING => ($notification->last_attempt_at ?? $notification->updated_at)
                ->lte(now()->subSeconds($this->queue->leaseSeconds($notification->metadata['queue_connection'] ?? null))),
            EventRegistrationNotification::STATUS_FAILED => ($notification->failed_at ?? $notification->last_attempt_at ?? $notification->updated_at)
                ->lte(now()->subSeconds($this->queue->retryDelay($notification->attempts))),
            default => false,
        };
    }
}
