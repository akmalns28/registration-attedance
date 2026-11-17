<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EventRegistration extends Model
{
    use HasFactory;

    public const TYPE_EMPLOYEE = 'employee';

    public const TYPE_EXTERNAL = 'external';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SELF = 'self';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['event_id', 'employee_id', 'external_participant_id', 'registration_type', 'registration_source', 'status', 'registered_at', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventRegistration $registration) {
            if (empty($registration->uuid)) {
                $registration->uuid = (string) Str::uuid7();
            }

            if (empty($registration->registration_number)) {
                $registration->registration_number = 'REG-'.strtoupper(str_replace('-', '', $registration->uuid));
            }

            if (empty($registration->registration_source)) {
                $registration->registration_source = self::SOURCE_ADMIN;
            }

            if (empty($registration->status)) {
                $registration->status = self::STATUS_REGISTERED;
            }

            if ($registration->status === self::STATUS_REGISTERED && $registration->registered_at === null) {
                $registration->registered_at = now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function types(): array
    {
        return [self::TYPE_EMPLOYEE, self::TYPE_EXTERNAL];
    }

    public static function statuses(): array
    {
        return [self::STATUS_REGISTERED, self::STATUS_CANCELLED];
    }

    public static function sources(): array
    {
        return [self::SOURCE_ADMIN, self::SOURCE_SELF];
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function externalParticipant(): BelongsTo
    {
        return $this->belongsTo(ExternalParticipant::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REGISTERED);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeEmployees(Builder $query): Builder
    {
        return $query->where('registration_type', self::TYPE_EMPLOYEE);
    }

    public function scopeExternals(Builder $query): Builder
    {
        return $query->where('registration_type', self::TYPE_EXTERNAL);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isRegistered(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isEmployee(): bool
    {
        return $this->registration_type === self::TYPE_EMPLOYEE;
    }

    public function isExternal(): bool
    {
        return $this->registration_type === self::TYPE_EXTERNAL;
    }

    public function getParticipantNameAttribute(): ?string
    {
        if ($this->isEmployee()) {
            return $this->employee?->name;
        }

        if ($this->isExternal()) {
            return $this->externalParticipant?->name;
        }

        return null;
    }

    /*
|--------------------------------------------------------------------------
| Notification History
|--------------------------------------------------------------------------
*/

    public function notifications(): HasMany
    {
        return $this->hasMany(EventRegistrationNotification::class, 'event_registration_id');
    }
}
