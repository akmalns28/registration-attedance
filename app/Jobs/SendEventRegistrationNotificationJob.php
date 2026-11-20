<?php

namespace App\Jobs;

use App\Mail\EventRegistrationMail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Services\EventNotificationEligibility;
use App\Services\EventNotificationQueue;
use App\Services\EventNotificationTransport;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendEventRegistrationNotificationJob implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public ?int $dispatchGeneration = null;

    public function __construct(public int $notificationId, ?int $dispatchGeneration = null)
    {
        $this->dispatchGeneration = $dispatchGeneration;
        $this->tries = max(1, (int) config('event_notifications.tries', 3));
        $this->timeout = max(1, (int) config('event_notifications.timeout', 60));
        $this->onQueue(config('event_notifications.queue', 'emails'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('event_notifications.backoff', [60, 300, 900]);
    }

    public function handle(): void
    {
        $notification = $this->claim();

        if ($notification === null) {
            return;
        }

        $token = $notification->metadata['processing_token'];

        /*
         * SMTP runs outside the transaction. A provider can accept a message
         * before we record sent_at; recovery is therefore at-least-once, never
         * exactly-once. The claim token fences updates from obsolete workers.
         */
        try {
            Mail::to(trim($notification->recipient_email))->send(new EventRegistrationMail($notification));

            $this->claimedQuery($token)->update([
                'status' => EventRegistrationNotification::STATUS_SENT,
                'sent_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $this->claimedQuery($token)->update([
                'status' => EventRegistrationNotification::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            Log::warning('Event registration email delivery failed.', [
                'notification_id' => $notification->id,
                'attempts' => $notification->attempts,
                'exception_type' => $exception::class,
            ]);

            throw new RuntimeException('Event registration email delivery failed (notification '.$notification->id.').');
        }
    }

    private function claim(): ?EventRegistrationNotification
    {
        $snapshot = EventRegistrationNotification::query()->find($this->notificationId);

        if ($snapshot === null) {
            return null;
        }

        return DB::transaction(function () use ($snapshot): ?EventRegistrationNotification {
            Event::query()->whereKey($snapshot->event_id)->lockForUpdate()->first();
            EventRegistration::query()->whereKey($snapshot->event_registration_id)->lockForUpdate()->first();
            $notification = EventRegistrationNotification::query()
                ->with(['registration.event', 'registration.employee', 'registration.externalParticipant', 'registration.activeInvitedDays'])
                ->whereKey($this->notificationId)->lockForUpdate()->first();

            if ($notification === null || ! $this->matchesGeneration($notification)
                || in_array($notification->status, [EventRegistrationNotification::STATUS_SENT, EventRegistrationNotification::STATUS_SKIPPED], true)) {
                return null;
            }

            $queue = app(EventNotificationQueue::class);
            $lastAttempt = $notification->last_attempt_at ?? $notification->updated_at;

            if ($notification->status === EventRegistrationNotification::STATUS_PROCESSING
                && $lastAttempt->isAfter(now()->subSeconds($queue->leaseSeconds($this->connection)))) {
                return null;
            }

            if ($notification->attempts >= max(1, (int) config('event_notifications.tries', 3))) {
                $notification->update([
                    'status' => EventRegistrationNotification::STATUS_FAILED,
                    'failed_at' => $notification->failed_at ?? now(),
                    'last_error' => 'Notification retry limit exhausted.',
                ]);

                return null;
            }

            if ($notification->scheduled_for?->isFuture()) {
                return null;
            }

            if ($reason = app(EventNotificationEligibility::class)->reason($notification)) {
                $notification->update([
                    'status' => EventRegistrationNotification::STATUS_SKIPPED,
                    'last_error' => $reason,
                ]);

                return null;
            }

            if ($problem = app(EventNotificationTransport::class)->problem()) {
                $notification->update([
                    'status' => EventRegistrationNotification::STATUS_PENDING,
                    'queued_at' => null,
                    'last_error' => $problem,
                ]);

                return null;
            }

            $metadata = $notification->metadata ?? [];
            $metadata['processing_token'] = (string) Str::uuid();
            $metadata['delivery_mailer'] = (string) config('mail.default');
            $metadata['delivery_transport'] = (string) config('mail.mailers.'.config('mail.default').'.transport');
            $notification->update([
                'status' => EventRegistrationNotification::STATUS_PROCESSING,
                'attempts' => $notification->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
                'metadata' => $metadata,
            ]);

            return $notification;
        }, attempts: 3);
    }

    private function matchesGeneration(EventRegistrationNotification $notification): bool
    {
        return (int) ($notification->metadata['dispatch_generation'] ?? 0) === (int) ($this->dispatchGeneration ?? 0);
    }

    private function claimedQuery(string $token): Builder
    {
        return EventRegistrationNotification::query()->whereKey($this->notificationId)
            ->where('status', EventRegistrationNotification::STATUS_PROCESSING)
            ->where('metadata->processing_token', $token);
    }

    public function failed(?Throwable $exception): void
    {
        $notification = EventRegistrationNotification::query()->find($this->notificationId);

        if ($notification === null || ! $this->matchesGeneration($notification)) {
            return;
        }

        $lease = app(EventNotificationQueue::class)->leaseSeconds($this->connection);

        EventRegistrationNotification::query()->whereKey($notification->id)
            ->where(function ($query): void {
                $query->where('metadata->dispatch_generation', (int) ($this->dispatchGeneration ?? 0));

                if ($this->dispatchGeneration === null || $this->dispatchGeneration === 0) {
                    $query->orWhereNull('metadata->dispatch_generation');
                }
            })
            ->where(function ($query) use ($lease): void {
                $query->whereIn('status', [
                    EventRegistrationNotification::STATUS_PENDING,
                    EventRegistrationNotification::STATUS_QUEUED,
                    EventRegistrationNotification::STATUS_FAILED,
                ])->orWhere(function ($query) use ($lease): void {
                    $query->where('status', EventRegistrationNotification::STATUS_PROCESSING)
                        ->where('last_attempt_at', '<=', now()->subSeconds($lease));
                });
            })->update([
                'status' => EventRegistrationNotification::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => $notification->last_error ?? ($exception ? mb_substr($exception->getMessage(), 0, 2000) : 'Queue job permanently failed.'),
            ]);
    }
}
