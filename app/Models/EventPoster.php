<?php

namespace App\Models;

use Database\Factories\EventPosterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EventPoster extends Model
{
    /** @use HasFactory<EventPosterFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['file_path', 'original_filename', 'mime_type', 'content_hash', 'is_primary', 'sort_order'];

    protected $attributes = ['is_event_wide' => true];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_event_wide' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function days(): BelongsToMany
    {
        return $this->belongsToMany(EventDay::class)->orderBy('day_number');
    }

    public function isEventWide(): bool
    {
        return $this->is_event_wide && $this->days->isEmpty();
    }

    /** @param list<int> $dayIds */
    public function appliesToDays(array $dayIds): bool
    {
        return $this->isEventWide() || array_intersect($dayIds, $this->days->modelKeys()) !== [];
    }

    public static function variantPath(string $posterPath, string $variant): string
    {
        return $posterPath.'.'.$variant.'.jpg';
    }
}
