<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationNotification extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Notification Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_INVITATION = 'invitation';

    public const TYPE_CANCELLATION = 'cancellation';

    public const TYPE_REACTIVATION = 'reactivation';

    public const TYPE_REMINDER = 'reminder';

    /*
    |--------------------------------------------------------------------------
    | Notification Statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = ['event_id', 'event_registration_id', 'notification_type', 'dedupe_key', 'recipient_email', 'status', 'attempts', 'scheduled_for', 'queued_at', 'last_attempt_at', 'sent_at', 'failed_at', 'last_error', 'metadata'];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'metadata' => 'array',
            'scheduled_for' => 'datetime',
            'queued_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Available Types
    |--------------------------------------------------------------------------
    */

    public static function types(): array
    {
        return [self::TYPE_INVITATION, self::TYPE_CANCELLATION, self::TYPE_REACTIVATION, self::TYPE_REMINDER];
    }

    /*
    |--------------------------------------------------------------------------
    | Available Statuses
    |--------------------------------------------------------------------------
    */

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeSkipped(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SKIPPED);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('notification_type', $type);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }
}
