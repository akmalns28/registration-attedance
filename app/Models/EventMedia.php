<?php

namespace App\Models;

use Database\Factories\EventMediaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMedia extends Model
{
    /** @use HasFactory<EventMediaFactory> */
    use HasFactory, HasUuids;

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    protected $table = 'event_media';

    protected $fillable = ['media_type', 'file_path', 'original_filename', 'title', 'description', 'mime_type', 'file_size', 'uploaded_by'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
