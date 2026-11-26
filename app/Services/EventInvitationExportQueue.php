<?php

namespace App\Services;

use App\Jobs\GenerateEventInvitationExport;
use App\Models\Event;
use App\Models\EventInvitationExport;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class EventInvitationExportQueue
{
    public const CONNECTION = 'invitation_exports';

    public const QUEUE = 'invitation-exports';

    public const MAX_BATCH_SIZE = 5;

    public function __construct(private readonly EventInvitationExportService $pdfs) {}

    /** @return Collection<int, EventRegistration>|EventInvitationExport */
    public function prepare(Event $event, User $requester, string $participantType): Collection|EventInvitationExport
    {
        $directLimit = min(self::MAX_BATCH_SIZE, max(0, (int) config('event_invitations.direct_limit')));

        return DB::transaction(function () use ($event, $requester, $participantType, $directLimit): Collection|EventInvitationExport {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $active = $lockedEvent->invitationExports()->where('requested_by', $requester->id)
                ->where('participant_type', $participantType)
                ->whereIn('status', [EventInvitationExport::PENDING, EventInvitationExport::PROCESSING])->first();
            if ($active !== null) {
                return $active;
            }

            $eligible = $this->pdfs->eligible($lockedEvent, $participantType);
            $stats = (clone $eligible)->selectRaw('COUNT(*) AS total, MAX(id) AS last_id')->first();
            if ((int) $stats->total === 0) {
                throw ValidationException::withMessages(['export' => 'Tidak ada peserta dengan hari undangan aktif untuk filter ini.']);
            }

            if ((int) $stats->total <= $directLimit) {
                return $this->pdfs->withInvitationDetails($eligible, $participantType)->where('id', '<=', $stats->last_id)->get();
            }

            $export = $lockedEvent->invitationExports()->create([
                'requested_by' => $requester->id,
                'participant_type' => $participantType,
                'participant_count' => $stats->total,
                'last_registration_id' => $stats->last_id,
                'batch_size' => min(self::MAX_BATCH_SIZE, max(1, (int) config('event_invitations.batch_size'))),
            ]);
            $this->enqueue($export);

            return $export;
        }, attempts: 3);
    }

    public function enqueue(EventInvitationExport $export): void
    {
        Queue::connection(self::CONNECTION)->push(new GenerateEventInvitationExport($export->id, $export->batch_number), queue: self::QUEUE);
    }

    /** @return list<EventInvitationExport> */
    public function recent(Event $event, User $user): array
    {
        $exports = [];
        foreach (['all', ...EventRegistration::types()] as $type) {
            $export = $event->invitationExports()->where('requested_by', $user->id)->where('participant_type', $type)
                ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()->orderByDesc('id')->first();
            if ($export !== null) {
                $exports[] = $export;
            }
        }

        return $exports;
    }
}
