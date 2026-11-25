<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventPoster;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventPoster>
 */
class EventPosterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'file_path' => 'event-posters/'.Str::uuid7().'.png',
            'original_filename' => 'poster.png',
            'mime_type' => 'image/png',
            'content_hash' => hash('sha256', (string) Str::uuid7()),
            'is_primary' => false,
            'sort_order' => 0,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
