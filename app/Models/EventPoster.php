<?php

namespace App\Models;

use Database\Factories\EventPosterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPoster extends Model
{
    /** @use HasFactory<EventPosterFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['file_path', 'original_filename', 'mime_type', 'content_hash', 'is_primary', 'sort_order'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public static function variantPath(string $posterPath, string $variant): string
    {
        return $posterPath.'.'.$variant.'.jpg';
    }
}
