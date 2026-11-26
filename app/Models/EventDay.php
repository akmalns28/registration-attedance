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

    protected $fillable = ['event_id', 'day_number', 'date', 'title', 'description', 'start_time', 'end_time', 'dress_code', 'is_active'];

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

    public function posters(): BelongsToMany
    {
        return $this->belongsToMany(EventPoster::class)->orderBy('sort_order')->orderBy('event_posters.id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('day_number');
    }

    public function attendanceTime(): ?string
    {
        $start = $this->start_time === null ? null : str_replace(':', '.', substr($this->start_time, 0, 5));
        $end = $this->end_time === null ? null : str_replace(':', '.', substr($this->end_time, 0, 5));

        return match (true) {
            $start !== null && $end !== null => $start.'–'.$end.' WIB',
            $start !== null => 'Mulai '.$start.' WIB',
            $end !== null => 'Selesai '.$end.' WIB',
            default => null,
        };
    }

    public function effectiveDressCode(Event $event): ?string
    {
        $override = trim($this->dress_code ?? '');
        $default = trim($event->dress_code ?? '');

        return $override !== '' ? $override : ($default !== '' ? $default : null);
    }

    /**
     * Store event date using Y-m-d format.
     */
    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
