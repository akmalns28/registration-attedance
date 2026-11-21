<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventDayInvitationService
{
    public function dateSummary(EventRegistration $registration): ?string
    {
        $dates = $registration->loadMissing('activeInvitedDays')->activeInvitedDays
            ->sortBy('date')->pluck('date')->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $format = fn (Carbon $date): string => $date->copy()->locale('id')->translatedFormat('j F Y');
        $first = $dates->first();
        $last = $dates->last();

        if ($dates->count() > 1 && $first->copy()->addDays($dates->count() - 1)->isSameDay($last)) {
            return $format($first).' – '.$format($last);
        }

        return $dates->map($format)->implode(', ');
    }

    /**
     * @param  list<int|string>|null  $requestedIds
     * @param  list<int>  $retainedIds
     * @return list<int>
     */
    public function resolveDays(Event $event, ?array $requestedIds, array $retainedIds = []): array
    {
        if ($requestedIds === []) {
            return [];
        }

        $days = $event->days()->where(function (EloquentBuilder $query) use ($retainedIds, $event): void {
            $query->where(fn (EloquentBuilder $active): EloquentBuilder => $this->availableDays($active, $event))
                ->orWhereIn('event_days.id', $retainedIds);
        });
        if ($requestedIds !== null) {
            $days->whereIntegerInRaw('event_days.id', $requestedIds);
        }
        $ids = $days->pluck('event_days.id')->all();

        if ($ids === [] || ($requestedIds !== null && count($ids) !== count(array_unique($requestedIds)))) {
            throw ValidationException::withMessages(['day_ids' => 'Pilih hari pelaksanaan aktif yang tersedia pada event ini.']);
        }

        return $ids;
    }

    /** @return list<int> */
    public function availableDayIds(Event $event): array
    {
        return $event->days()->where(fn (EloquentBuilder $query): EloquentBuilder => $this->availableDays($query, $event))
            ->pluck('event_days.id')->all();
    }

    private function availableDays(EloquentBuilder $query, Event $event): EloquentBuilder
    {
        return $query->active()->whereBetween('date', [$event->start_date->toDateString(), $event->end_date->toDateString()]);
    }

    /** @return list<int> */
    public function protectedDayIds(EventRegistration $registration): array
    {
        return $this->withRecordedAttendance($this->activeInvitations([$registration->id]))->pluck('event_day_id')->all();
    }

    /**
     * @param  list<int>  $registrationIds
     * @param  list<int>  $dayIds
     */
    public function grant(array $registrationIds, array $dayIds): void
    {
        $rows = [];
        foreach ($registrationIds as $registrationId) {
            foreach ($dayIds as $dayId) {
                $rows[] = ['event_registration_id' => $registrationId, 'event_day_id' => $dayId, 'revoked_at' => null];
                if (count($rows) === 200) {
                    $this->upsert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            $this->upsert($rows);
        }
    }

    /** @param list<array{event_registration_id: int, event_day_id: int, revoked_at: null}> $rows */
    private function upsert(array $rows): void
    {
        DB::table('event_day_invitations')->upsert($rows, ['event_registration_id', 'event_day_id'], ['revoked_at']);
    }

    /**
     * @param  list<int>  $registrationIds
     * @param  list<int>  $dayIds
     */
    public function revokeExcept(array $registrationIds, array $dayIds): void
    {
        if ($registrationIds === []) {
            return;
        }

        $revoked = $this->activeInvitations($registrationIds)->whereIntegerNotInRaw('event_day_id', $dayIds);
        if ($this->withRecordedAttendance(clone $revoked)->exists()) {
            throw ValidationException::withMessages(['day_ids' => 'Hari dengan kehadiran tercatat tidak dapat dicabut.']);
        }

        $revoked->update(['revoked_at' => now()]);
    }

    /** @param list<int> $registrationIds */
    private function activeInvitations(array $registrationIds): Builder
    {
        return DB::table('event_day_invitations')->whereIntegerInRaw('event_registration_id', $registrationIds)->whereNull('revoked_at');
    }

    private function withRecordedAttendance(Builder $invitations): Builder
    {
        return $invitations->whereExists(fn (Builder $query): Builder => $query->from('attendances')
            ->whereColumn('attendances.event_registration_id', 'event_day_invitations.event_registration_id')
            ->whereColumn('attendances.event_day_id', 'event_day_invitations.event_day_id'));
    }

    /** @param list<int|string> $requestedIds */
    public function replace(Event $event, EventRegistration $registration, array $requestedIds): void
    {
        DB::transaction(function () use ($event, $registration, $requestedIds): void {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $registration = $event->registrations()->whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if (! $registration->isRegistered() || $event->status === Event::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['day_ids' => 'Undangan hanya dapat diubah untuk registrasi dan event yang tidak dibatalkan.']);
            }

            $currentIds = $registration->invitedDays()->pluck('event_days.id')->all();
            $dayIds = $this->resolveDays($event, $requestedIds, $currentIds);
            $this->revokeExcept([$registration->id], $dayIds);
            $this->grant([$registration->id], array_values(array_diff($dayIds, $currentIds)));
        }, attempts: 3);
    }
}
