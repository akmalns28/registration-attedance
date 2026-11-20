<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class EventDay extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'day_number', 'date', 'title', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'day_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EventSchedule::class)->orderBy('start_time');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function invitations(): BelongsToMany
    {
        return $this->belongsToMany(EventRegistration::class, 'event_day_invitations')->withPivot('revoked_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('day_number');
    }

    /**
     * Store event date using Y-m-d format.
     */
    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
