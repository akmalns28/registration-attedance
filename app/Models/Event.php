<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['title', 'description', 'location', 'start_date', 'end_date', 'registration_start_at', 'registration_end_at', 'capacity', 'status'];

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid7();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',

            'registration_start_at' => 'datetime',
            'registration_end_at' => 'datetime',

            'capacity' => 'integer',
        ];
    }

    /**
     * Use UUID for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Available event statuses.
     */
    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CLOSED, self::STATUS_CANCELLED];
    }

    /**
     * Scope published events.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Scope draft events.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Check whether event is published.
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Check whether event spans multiple days.
     */
    public function isMultiDay(): bool
    {
        return $this->start_date->isBefore($this->end_date);
    }

    public function deletionProhibitedReason(): ?string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return 'Event Cancelled tidak dapat dihapus agar riwayat operasional tetap tersimpan.';
        }

        if ($this->status !== self::STATUS_DRAFT) {
            return 'Hanya event berstatus Draft yang dapat dihapus. Gunakan status Cancelled untuk membatalkan event dan menjaga riwayat operasional.';
        }

        if ($this->registration_notifications_exists ?? $this->registrationNotifications()->exists()) {
            return 'Event memiliki riwayat notifikasi dan tidak dapat dihapus. Gunakan status Cancelled untuk membatalkan event dan menjaga riwayat operasional.';
        }

        if ($this->registrations_exists ?? $this->registrations()->exists()) {
            return 'Event memiliki riwayat registrasi dan tidak dapat dihapus, termasuk registrasi yang dibatalkan. Gunakan status Cancelled untuk membatalkan event dan menjaga riwayat operasional.';
        }

        if ($this->days_exists ?? $this->days()->exists()) {
            return 'Event yang sudah memiliki hari pelaksanaan tidak dapat dihapus. Hapus rundown terlebih dahulu, lalu hapus seluruh hari pelaksanaan.';
        }

        return null;
    }

    public function days(): HasMany
    {
        return $this->hasMany(EventDay::class)->orderBy('day_number');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /*
|--------------------------------------------------------------------------
| Registration Notifications
|--------------------------------------------------------------------------
*/

    public function registrationNotifications(): HasMany
    {
        return $this->hasMany(EventRegistrationNotification::class);
    }
}
