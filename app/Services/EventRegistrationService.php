<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\ExternalParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EventRegistrationService
{
    public function __construct(
        private readonly EventPublicationNotificationService $notifications,
        private readonly EventDayInvitationService $invitations,
    ) {}

    /**
     * Bulk register employees.
     *
     * @param  list<int|string>|null  $dayIds  Null selects all current active days. An empty list grants none.
     */
    public function registerEmployees(Event $event, array $employeeIds, ?array $dayIds = []): array
    {
        return $this->register($event, EventRegistration::TYPE_EMPLOYEE, $employeeIds, $dayIds);
    }

    /**
     * Bulk register external participants.
     *
     * @param  list<int|string>|null  $dayIds  Null selects all current active days. An empty list grants none.
     */
    public function registerExternals(Event $event, array $externalParticipantIds, ?array $dayIds = []): array
    {
        return $this->register($event, EventRegistration::TYPE_EXTERNAL, $externalParticipantIds, $dayIds);
    }

    /**
     * Register participants within a transaction.
     *
     * @return array{
     *     created: int,
     *     reactivated: int,
     *     skipped: int,
     *     total: int
     * }
     */
    private function register(Event $event, string $type, array $participantIds, ?array $requestedDayIds = [], bool $withinTransaction = false): array
    {
        /*
    |--------------------------------------------------------------------------
    | Validate Participant Type
    |--------------------------------------------------------------------------
    */

        if (! in_array($type, EventRegistration::types(), true)) {
            throw new InvalidArgumentException('Jenis peserta tidak valid.');
        }

        /*
    |--------------------------------------------------------------------------
    | Normalize Participant IDs
    |--------------------------------------------------------------------------
    */

        if (count($participantIds) === 0 || count($participantIds) > 500) {
            throw ValidationException::withMessages([
                'participants' => 'Jumlah peserta harus antara 1 sampai 500.',
            ]);
        }

        $normalizedIds = [];

        foreach ($participantIds as $id) {
            if (! is_int($id) && ! is_string($id)) {
                throw ValidationException::withMessages([
                    'participants' => 'ID peserta harus berupa bilangan bulat positif.',
                ]);
            }

            $value = (string) $id;

            // Only positive integer representations are allowed.
            if (! preg_match('/^[0-9]+$/D', $value)) {
                throw ValidationException::withMessages([
                    'participants' => 'ID peserta harus berupa bilangan bulat positif.',
                ]);
            }

            // Normalize leading zeros: "0001" => "1".
            $value = ltrim($value, '0');

            // Reject zero and values exceeding PHP integer range.
            if ($value === '' || strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
                throw ValidationException::withMessages([
                    'participants' => 'ID peserta harus berupa bilangan bulat positif.',
                ]);
            }

            $normalizedIds[] = (int) $value;
        }

        // Deduplicate after normalization.
        $ids = collect($normalizedIds)->unique()->values();

        /*
    |--------------------------------------------------------------------------
    | Database Transaction
    |--------------------------------------------------------------------------
    */

        $register = function () use ($event, $type, $ids, $requestedDayIds): array {
            /*
        |--------------------------------------------------------------------------
        | Lock Event
        |--------------------------------------------------------------------------
        */

            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Validate Event Status
        |--------------------------------------------------------------------------
        */

            if (! in_array($lockedEvent->status, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED], true)) {
                throw ValidationException::withMessages([
                    'event' => 'Event tidak menerima registrasi peserta.',
                ]);
            }
            $dayIds = $this->invitations->resolveDays($lockedEvent, $requestedDayIds);

            /*
        |--------------------------------------------------------------------------
        | Resolve Participant Model
        |--------------------------------------------------------------------------
        */

            $participantModel = match ($type) {
                EventRegistration::TYPE_EMPLOYEE => Employee::class,
                EventRegistration::TYPE_EXTERNAL => ExternalParticipant::class,
            };

            $participantColumn = match ($type) {
                EventRegistration::TYPE_EMPLOYEE => 'employee_id',
                EventRegistration::TYPE_EXTERNAL => 'external_participant_id',
            };

            /*
        |--------------------------------------------------------------------------
        | Validate Active Participants
        |--------------------------------------------------------------------------
        */

            $activeIds = $participantModel::query()->whereIn('id', $ids->all())->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id);

            if ($activeIds->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'participants' => 'Terdapat peserta yang tidak ditemukan atau tidak aktif.',
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Existing Registrations
        |--------------------------------------------------------------------------
        */

            $existing = EventRegistration::query()->where('event_id', $lockedEvent->id)->where('registration_type', $type)->whereIn($participantColumn, $ids->all())->get()->keyBy($participantColumn);

            $toCreate = [];
            $toReactivate = [];
            $skipped = 0;

            foreach ($ids as $id) {
                $registration = $existing->get($id);

                if (! $registration) {
                    $toCreate[] = $id;

                    continue;
                }

                if ($registration->isCancelled()) {
                    $toReactivate[] = $registration;

                    continue;
                }

                $skipped++;
            }

            /*
        |--------------------------------------------------------------------------
        | Capacity Validation
        |--------------------------------------------------------------------------
        */

            $additionalSlots = count($toCreate) + count($toReactivate);

            $registeredCount = $lockedEvent->registrations()->registered()->count();

            if ($lockedEvent->capacity !== null && $registeredCount + $additionalSlots > $lockedEvent->capacity) {
                $remaining = max(0, $lockedEvent->capacity - $registeredCount);

                throw ValidationException::withMessages([
                    'participants' => "Kapasitas tidak mencukupi. Sisa kuota: {$remaining} peserta.",
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Create Registrations
        |--------------------------------------------------------------------------
        */

            $createdRegistrations = [];
            foreach ($toCreate as $id) {
                $createdRegistrations[] = $lockedEvent->registrations()->create([
                    $participantColumn => $id,
                    'registration_type' => $type,
                    'registration_source' => EventRegistration::SOURCE_ADMIN,
                    'status' => EventRegistration::STATUS_REGISTERED,
                    'registered_at' => now(),
                    'cancelled_at' => null,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Reactivate Registrations
        |--------------------------------------------------------------------------
        */

            foreach ($toReactivate as $registration) {
                $registration->update([
                    'status' => EventRegistration::STATUS_REGISTERED,
                    'registered_at' => now(),
                    'cancelled_at' => null,
                ]);
            }

            $reactivatedIds = array_map(fn (EventRegistration $registration): int => $registration->id, $toReactivate);
            if ($requestedDayIds !== []) {
                $this->invitations->revokeExcept($reactivatedIds, $dayIds);
            }
            $newIds = array_map(fn (EventRegistration $registration): int => $registration->id, $createdRegistrations);
            $this->invitations->grant([...$newIds, ...$reactivatedIds], $dayIds);

            if ($lockedEvent->isPublished()) {
                foreach ($createdRegistrations as $registration) {
                    $this->notifications->scheduleForRegistration($registration);
                }
                foreach ($toReactivate as $registration) {
                    $this->notifications->scheduleStatusChange($registration, EventRegistrationNotification::TYPE_REACTIVATION, true);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Registration Result
        |--------------------------------------------------------------------------
        */

            return [
                'created' => count($toCreate),
                'reactivated' => count($toReactivate),
                'skipped' => $skipped,
                'total' => $ids->count(),
            ];
        };

        return $withinTransaction ? $register() : DB::transaction($register, attempts: 3);
    }

    /**
     * Cancel a registration without deleting history.
     */
    public function cancel(EventRegistration $registration): EventRegistration
    {
        return DB::transaction(function () use ($registration) {
            /*
        |--------------------------------------------------------------------------
        | Lock Event
        |--------------------------------------------------------------------------
        */

            $event = Event::query()->whereKey($registration->event_id)->lockForUpdate()->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Load Registration
        |--------------------------------------------------------------------------
        */

            $lockedRegistration = EventRegistration::query()->where('event_id', $event->id)->whereKey($registration->getKey())->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Already Cancelled
        |--------------------------------------------------------------------------
        */

            if ($lockedRegistration->isCancelled()) {
                return $lockedRegistration;
            }

            /*
        |--------------------------------------------------------------------------
        | Cancel Registration
        |--------------------------------------------------------------------------
        */

            $lockedRegistration->update([
                'status' => EventRegistration::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            /*
        |--------------------------------------------------------------------------
        | Schedule Cancellation Notification
        |--------------------------------------------------------------------------
        */

            if ($event->status === Event::STATUS_PUBLISHED) {
                $this->notifications->scheduleStatusChange($lockedRegistration, EventRegistrationNotification::TYPE_CANCELLATION, true);
            }

            return $lockedRegistration->refresh();
        }, attempts: 3);
    }

    /**
     * Reactivate an existing registration.
     *
     * Reuses the existing registration workflow to preserve:
     * - Event status validation
     * - Active participant validation
     * - Capacity validation
     * - Row locking
     * - Existing registration identity
     *
     * @throws ValidationException
     */
    public function reactivate(EventRegistration $registration): EventRegistration
    {
        return DB::transaction(function () use ($registration): EventRegistration {
            $event = Event::query()->whereKey($registration->event_id)->lockForUpdate()->firstOrFail();
            $registration = EventRegistration::query()->whereKey($registration->id)
                ->where('event_id', $event->id)->lockForUpdate()->firstOrFail();

            $participantId = match ($registration->registration_type) {
                EventRegistration::TYPE_EMPLOYEE => $registration->employee_id,
                EventRegistration::TYPE_EXTERNAL => $registration->external_participant_id,
                default => throw ValidationException::withMessages(['registration' => 'Jenis registrasi tidak valid.']),
            };

            if ($registration->isRegistered()) {
                return $registration;
            }

            $this->register($event, $registration->registration_type, [$participantId], withinTransaction: true);

            return $registration->refresh();
        }, attempts: 3);
    }
}
