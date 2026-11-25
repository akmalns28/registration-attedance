<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventMedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventMedia>
 */
class EventMediaFactory extends Factory
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
            'media_type' => 'image',
            'file_path' => 'event-gallery/'.Str::uuid7().'.png',
            'original_filename' => 'dokumentasi.png',
            'title' => fake()->words(3, true),
            'description' => null,
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'uploaded_by' => User::factory(),
        ];
    }
}
