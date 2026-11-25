<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventInvitationExport extends Model
{
    use HasFactory, HasUuids;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = ['requested_by', 'participant_type', 'status', 'participant_count', 'processed_count',
        'batch_size', 'batch_number', 'last_registration_id', 'cursor', 'peak_memory_bytes', 'expires_at'];

    protected $attributes = ['status' => self::PENDING, 'processed_count' => 0, 'batch_number' => 0, 'cursor' => 0, 'peak_memory_bytes' => 0];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING], true);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::COMPLETED && $this->expires_at?->isFuture();
    }

    public function directory(): string
    {
        return 'event-invitation-exports/'.$this->id;
    }

    public function archivePath(): string
    {
        return $this->directory().'/invitations.zip';
    }

    public function batchPath(int $batch): string
    {
        return $this->directory().'/batches/'.sprintf('undangan-%06d.pdf', $batch + 1);
    }
}
