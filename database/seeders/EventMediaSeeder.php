<?php

namespace Database\Seeders;

use App\Models\EventMedia;
use App\Services\EventAssetStorage;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class EventMediaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(EventAssetStorage $storage): void
    {
        $storage->saveUploads([UploadedFile::fake()->image('dokumentasi.png', 640, 480)], 'event-gallery', 'files',
            fn (array $uploads): EventMedia => EventMedia::factory()->create($uploads[0]));
    }
}
