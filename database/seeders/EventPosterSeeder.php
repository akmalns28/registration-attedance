<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Services\EventPosterService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class EventPosterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(EventPosterService $posters): void
    {
        $posters->saveUploads([UploadedFile::fake()->image('poster.png', 420, 600)],
            fn (array $uploads): array => DB::transaction(fn (): array => $posters->sync(Event::factory()->create(), $uploads, [])));
    }
}
