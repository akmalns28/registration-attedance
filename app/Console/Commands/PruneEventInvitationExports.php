<?php

namespace App\Console\Commands;

use App\Models\EventInvitationExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('app:prune-event-invitation-exports')]
#[Description('Remove expired invitation exports and abandoned batches')]
class PruneEventInvitationExports extends Command
{
    public function handle(): int
    {
        $expiredAt = now();
        $staleAt = $expiredAt->copy()->subHours(max(24, (int) config('event_invitations.retention_hours')));
        $expired = EventInvitationExport::query()
            ->where(fn (Builder $query): Builder => $query->where('expires_at', '<=', $expiredAt)
                ->orWhere(fn (Builder $active): Builder => $active->whereNull('expires_at')->where('updated_at', '<=', $staleAt)));
        $disk = Storage::disk('local');
        $failures = 0;

        foreach ((clone $expired)->lazyById() as $export) {
            DB::transaction(function () use ($expired, $export, $disk, &$failures): void {
                $current = (clone $expired)->whereKey($export->id)->lockForUpdate()->first();
                if ($current === null) {
                    return;
                }
                if ($disk->directoryExists($current->directory()) && ! $disk->deleteDirectory($current->directory())) {
                    $this->warn("Could not prune invitation export {$current->id}.");
                    $failures++;

                    return;
                }

                $current->delete();
            });
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
