<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventPoster;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EventPosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_active' => true])->assignRole('event-admin'));
    }

    public function test_single_upload_becomes_primary_and_is_visible_in_overview_and_edit(): void
    {
        $poster = UploadedFile::fake()->image('invite.jpg', 80, 120);
        $logo = UploadedFile::fake()->image('logo.png');
        $this->post(route('events.store'), [...$this->eventData(), 'posters' => [$poster], 'logo' => $logo])->assertSessionHasNoErrors();

        $event = Event::query()->sole();
        $primary = $event->posters()->sole();
        $this->assertTrue($primary->is_primary);
        $this->assertSame($primary->id, $event->primaryPoster->id);
        $this->assertSame('invite.jpg', $primary->original_filename);
        Storage::disk('local')->assertExists([$primary->file_path, $event->logo_path]);
        $url = route('events.posters.show', [$event, $primary]);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('events.show', $event))->assertOk()->assertSee($url)->assertSee(route('events.logo', $event));
        $this->get(route('events.edit', $event))->assertOk()->assertSee($url)->assertSee('name="posters[]"', false)
            ->assertSee('Atur pilihan hari setelah Event Day dibuat.');
        $this->get(route('events.posters.show', [Event::factory()->create(), $primary]))->assertNotFound();
        $this->actingAs(User::factory()->create(['is_active' => true]))->get($url)->assertForbidden();
    }

    public function test_posters_can_share_days_and_remain_event_wide_until_explicitly_assigned(): void
    {
        $event = Event::factory()->create($this->eventData());
        [$firstDay, $secondDay] = $this->posterDays($event);
        $legacy = EventPoster::factory()->for($event)->primary()->create(['original_filename' => 'legacy.png']);
        $firstFile = UploadedFile::fake()->image('first-day.jpg', 90, 110);
        $response = $this->put(route('events.update', $event), [...$this->eventData(),
            'posters' => [$firstFile, UploadedFile::fake()->image('second-day.jpg', 100, 110), UploadedFile::fake()->image('shared.jpg', 120, 110)],
            'poster_assignments' => [
                'upload:0' => ['scope' => 'days', 'days' => [$firstDay->id]],
                'upload:1' => ['scope' => 'days', 'days' => [$secondDay->id]],
                'upload:2' => ['scope' => 'days', 'days' => [$secondDay->id, $firstDay->id, $firstDay->id]],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        [, $first, $second, $shared] = $event->posters()->get();
        $this->assertTrue($legacy->isEventWide());
        $this->assertSame([$firstDay->id], $first->days->modelKeys());
        $this->assertSame([$secondDay->id], $second->days->modelKeys());
        $this->assertSame([$firstDay->id, $secondDay->id], $shared->days->modelKeys());
        $this->assertSame([$first->id, $shared->id], $firstDay->posters->modelKeys());
        $this->assertDatabaseCount('event_day_event_poster', 4);

        $this->get(route('events.show', [$event, 'poster_day' => $firstDay->id]))->assertOk()
            ->assertSee('Poster utama')->assertSee('first-day.jpg')->assertSee('shared.jpg')->assertDontSee('second-day.jpg');
        $this->get(route('events.show', [$event, 'poster_day' => $secondDay->id]))->assertOk()
            ->assertSee('second-day.jpg')->assertSee('Day 1 · Opening, Day 2 · Closing')->assertDontSee('first-day.jpg');

        $this->put(route('events.update', $event), [...$this->eventData(), 'title' => 'Unrelated edit'])->assertSessionHasNoErrors();
        $this->assertSame([$firstDay->id], $first->fresh()->days->modelKeys());

        $this->put(route('events.update', $event), [...$this->eventData(), 'title' => 'Unrelated edit', 'posters' => [$firstFile]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$firstDay->id], $first->fresh()->days->modelKeys());
        $this->assertSame([$firstDay->id, $secondDay->id], $shared->fresh()->days->modelKeys());
        $this->assertDatabaseCount('event_posters', 4);
        $this->assertCount(12, Storage::disk('local')->files('event-posters'));

        $this->put(route('events.update', $event), [...$this->eventData(), 'title' => 'Unrelated edit',
            'poster_assignments' => [$first->id => ['scope' => 'all']],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($first->fresh()->isEventWide());
        $this->assertSame([], $first->fresh()->days->modelKeys());

        $form = $this->get(route('events.edit', $event))->assertOk()->assertSee('2 hari')->assertSee('16/01/2027');
        if ($directory = getenv('POSTER_BROWSER_ARTIFACT_DIR')) {
            file_put_contents($directory.'/edit.html', $form->getContent());
            $this->from(route('events.edit', $event))->put(route('events.update', $event), [...$this->eventData(), 'title' => '',
                'poster_assignments' => ['upload:0' => [
                    'scope' => 'days', 'days' => [$secondDay->id], 'upload_reference' => '["retry.jpg",3,1234]',
                ]],
            ])->assertSessionHasErrors('title');
            file_put_contents($directory.'/retry.html', $this->get(route('events.edit', $event))->assertOk()->getContent());
        }
    }

    public function test_stale_or_cross_event_assignments_are_rejected_without_modifying_posters(): void
    {
        $event = Event::factory()->create($this->eventData());
        [$first, $second] = $this->posterDays($event);
        $foreignDay = EventDay::factory()->create();
        $poster = EventPoster::factory()->for($event)->primary()->create();
        $poster->days()->attach($first);
        $foreignPoster = EventPoster::factory()->create();
        $edit = route('events.edit', $event);
        $update = route('events.update', $event);

        $this->from($edit)->put($update, [...$this->eventData(), 'title' => 'Rejected',
            'poster_assignments' => [$poster->id => ['scope' => 'days', 'days' => [$second->id, $foreignDay->id]]],
        ])->assertRedirect($edit)->assertSessionHasErrors(['poster_assignments' => 'Hari pelaksanaan yang dipilih tidak tersedia pada event ini.'])
            ->assertSessionHasInput('poster_assignments.'.$poster->id.'.days.0', $second->id);
        $form = $this->get($edit)->assertOk()->assertSee('data-poster-assignment-dirty open', false);
        $this->assertMatchesRegularExpression('/data-poster-day value="'.$second->id.'"\s+checked/', $form->getContent());

        $this->put($update, [...$this->eventData(), 'poster_assignments' => [$foreignPoster->id => ['scope' => 'all']]])
            ->assertSessionHasErrors('poster_assignments');
        $this->put($update, [...$this->eventData(), 'poster_assignments' => [$poster->id => ['scope' => 'days']]])
            ->assertSessionHasErrors('poster_assignments');
        $this->put($update, [...$this->eventData(),
            'poster_assignments' => [$poster->id => ['scope' => 'days', 'day_count' => 2, 'days' => [$second->id]]],
        ])->assertSessionHasErrors(['poster_assignments' => 'Pilihan hari untuk poster tidak lengkap. Pilih kembali hari yang diinginkan.']);
        $this->put($update, [...$this->eventData(), 'poster_assignments' => [$poster->id => ['days' => [$second->id]]]])
            ->assertSessionHasErrors('poster_assignments.'.$poster->id.'.scope');
        $this->assertSame([$first->id], $poster->fresh()->days->modelKeys());
        $this->assertSame('Forum', $event->fresh()->title);
    }

    public function test_removing_days_prunes_links_without_making_scoped_posters_event_wide(): void
    {
        $event = Event::factory()->create($this->eventData());
        [$first, $second] = $this->posterDays($event);
        $this->put(route('events.update', $event), [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('shared.jpg')],
            'poster_assignments' => ['upload:0' => ['scope' => 'days', 'days' => [$first->id, $second->id]]],
        ])->assertSessionHasNoErrors();
        $poster = $event->posters()->sole();

        $this->delete(route('events.days.destroy', [$event, $first]))->assertSessionHasNoErrors();
        $this->assertSame([$second->id], $poster->fresh()->days->modelKeys());
        $this->delete(route('events.days.destroy', [$event, $second]))->assertSessionHasNoErrors();
        $this->assertFalse($poster->fresh()->isEventWide());
        $this->assertDatabaseCount('event_day_event_poster', 0);
        Storage::disk('local')->assertExists([$poster->file_path, ...$this->variants($poster)]);
    }

    public function test_removing_an_assigned_poster_preserves_other_posters_and_days(): void
    {
        $event = Event::factory()->create($this->eventData());
        [$day] = $this->posterDays($event);
        $this->put(route('events.update', $event), [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('remove.jpg')],
            'poster_assignments' => ['upload:0' => ['scope' => 'days', 'days' => [$day->id]]],
        ])->assertSessionHasNoErrors();
        $poster = $event->posters()->sole();
        $kept = EventPoster::factory()->for($event)->create();
        $kept->days()->attach($day);

        $this->put(route('events.update', $event), [...$this->eventData(), 'poster_remove' => [$poster->id]])->assertSessionHasNoErrors();

        $this->assertModelMissing($poster);
        $this->assertSame([$kept->id], $day->posters->modelKeys());
        $this->assertSame($kept->id, $event->primaryPoster()->sole()->id);
        Storage::disk('local')->assertMissing([$poster->file_path, ...$this->variants($poster)]);
    }

    public function test_edit_preserves_posters_and_can_add_reorder_choose_primary_and_remove_them(): void
    {
        $firstFile = UploadedFile::fake()->image('first.png', 90, 110);
        $secondFile = UploadedFile::fake()->image('second.jpg', 100, 130);
        $this->post(route('events.store'), [...$this->eventData(), 'posters' => [$firstFile, $secondFile]])->assertSessionHasNoErrors();
        $event = Event::query()->sole();
        [$first, $second] = $event->posters()->get();
        $edit = route('events.update', $event);

        $this->put($edit, $this->eventData())->assertSessionHasNoErrors();
        $this->assertSame([$first->id, $second->id], $event->posters()->get()->modelKeys());

        $thirdFile = UploadedFile::fake()->image('third.png', 120, 90);
        $this->put($edit, [...$this->eventData(), 'posters' => [$firstFile, $thirdFile],
            'poster_order' => [$second->id, 'upload:1', $first->id], 'poster_primary' => 'upload:1'])
            ->assertSessionHasNoErrors();
        $ordered = $event->posters()->get();
        $third = $ordered[1];
        $this->assertSame([$second->id, $third->id, $first->id], $ordered->modelKeys());
        $this->assertSame($third->id, $ordered->where('is_primary', true)->sole()->id);
        $this->assertCount(12, Storage::disk('local')->files('event-posters'));

        $this->put($edit, [...$this->eventData(), 'poster_remove' => [$third->id]])->assertSessionHasNoErrors();
        $this->assertSame($second->id, $event->primaryPoster()->sole()->id);
        Storage::disk('local')->assertMissing([$third->file_path, ...$this->variants($third)]);
        $this->put($edit, [...$this->eventData(), 'poster_remove' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertSame([], Storage::disk('local')->files('event-posters'));
        $this->get(route('events.show', $event))->assertOk()->assertDontSee('Poster utama');
    }

    public function test_unsupported_files_and_an_over_limit_update_preserve_the_existing_files(): void
    {
        config(['event_assets.poster_max_count' => 1, 'event_assets.poster_max_kb' => 20]);
        $this->post(route('events.store'), [...$this->eventData(), 'posters' => [UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')]])
            ->assertSessionHasErrors('posters.0');
        $this->post(route('events.store'), [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('big.jpg')->size(21)]])
            ->assertSessionHasErrors('posters.0');
        $this->post(route('events.store'), [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('saved.png')]])
            ->assertSessionHasNoErrors();
        $event = Event::query()->sole();
        $poster = $event->posters()->sole();
        $this->put(route('events.update', $event), [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('other.jpg')]])
            ->assertSessionHasErrors('posters');
        $this->assertCount(4, Storage::disk('local')->files('event-posters'));
        Storage::disk('local')->assertExists([$poster->file_path, ...$this->variants($poster)]);
        $this->assertTrue($poster->fresh()->is_primary);

        $this->actingAs(User::factory()->create(['is_active' => true])->assignRole('super-admin'))
            ->delete(route('events.destroy', $event))->assertRedirect(route('events.index'));
        $this->assertDatabaseCount('event_posters', 0);
        Storage::disk('local')->assertMissing([$poster->file_path, ...$this->variants($poster)]);
    }

    public function test_a_real_22_mib_poster_over_4000_pixels_generates_three_optimized_derivatives(): void
    {
        $file = tmpfile();
        $path = stream_get_meta_data($file)['uri'];
        $image = imagecreatetruecolor(4300, 1800);
        imagepng($image, $path, 0);
        unset($image);
        clearstatcache(true, $path);

        try {
            $this->assertGreaterThan(22 * 1024 * 1024, filesize($path));
            $this->assertLessThan(30 * 1024 * 1024, filesize($path));
            $this->post(route('events.store'), [...$this->eventData(), 'posters' => [
                new UploadedFile($path, 'large.png', test: true),
            ]])->assertSessionHasNoErrors();
        } finally {
            fclose($file);
        }

        $event = Event::query()->sole();
        $poster = $event->posters()->sole();
        $disk = Storage::disk('local');
        $this->assertGreaterThan(22 * 1024 * 1024, $disk->size($poster->file_path));
        foreach (config('event_assets.poster_sizes') as $variant => $target) {
            $derivative = EventPoster::variantPath($poster->file_path, $variant);
            [$width, $height, $type] = getimagesize($disk->path($derivative));
            $this->assertSame([$target, (int) round($target * 1800 / 4300), IMAGETYPE_JPEG], [$width, $height, $type]);
            $this->assertLessThan(512 * 1024, $disk->size($derivative));
        }

        foreach (['thumbnail', 'detail'] as $size) {
            $this->get(route('events.posters.show', [$event, $poster, 'size' => $size]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        }
    }

    #[TestWith([1200, 1800, 'portrait.jpg'])]
    #[TestWith([1800, 800, 'landscape.webp'])]
    #[TestWith([1700, 1700, 'square.png'])]
    public function test_derivatives_preserve_portrait_landscape_and_square_aspect_ratios(int $width, int $height, string $filename): void
    {
        $this->post(route('events.store'), [...$this->eventData(), 'posters' => [
            UploadedFile::fake()->image($filename, $width, $height),
        ]])->assertSessionHasNoErrors();

        $poster = EventPoster::query()->sole();
        $disk = Storage::disk('local');
        foreach ($this->variants($poster) as $derivative) {
            [$actualWidth, $actualHeight] = getimagesize($disk->path($derivative));
            $this->assertEqualsWithDelta($width / $height, $actualWidth / $actualHeight, 0.01);
        }
    }

    public function test_rejects_over_limit_file_request_and_metadata_in_indonesian(): void
    {
        $url = route('events.store');
        $this->post($url, [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('over-size.jpg')->size(30721)]])
            ->assertSessionHasErrors(['posters.0' => 'Ukuran setiap poster maksimal 30 MB.']);
        $this->post($url, [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('wide.png', 10001, 12)]])
            ->assertSessionHasErrors(['posters.0' => 'Dimensi poster terlalu besar. Maksimal 10.000 piksel pada sisi terpanjang dan 36 megapiksel.']);
        $this->call('POST', $url, $this->eventData(), server: ['CONTENT_LENGTH' => 64 * 1024 * 1024 + 1])
            ->assertStatus(413)->assertSee('Unggahan terlalu besar');
        $this->assertDatabaseCount('events', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('event-posters'));
    }

    public function test_processing_failure_rolls_back_multiple_uploads_and_keeps_existing_poster_files(): void
    {
        $this->post(route('events.store'), [...$this->eventData(), 'posters' => [UploadedFile::fake()->image('existing.png')]])
            ->assertSessionHasNoErrors();
        $event = Event::query()->sole();
        $poster = $event->posters()->sole();
        $disk = Storage::disk('local');
        $existing = [$poster->file_path, ...$this->variants($poster)];
        $hashes = array_map(fn (string $path): string => hash_file('sha256', $disk->path($path)), $existing);
        $png = UploadedFile::fake()->image('new.png', 200, 400);
        $corrupt = UploadedFile::fake()->createWithContent('corrupt.png', substr(file_get_contents($png->getPathname()), 0, 33));

        $this->put(route('events.update', $event), [
            ...$this->eventData(), 'title' => 'Changed', 'poster_remove' => [$poster->id], 'posters' => [$png, $corrupt],
        ])->assertSessionHasErrors(['posters.1' => 'Poster gagal diproses. Simpan ulang gambar sebagai JPG atau PNG, lalu unggah kembali.']);
        $this->assertSame('Forum', $event->fresh()->title);
        $this->assertSame($poster->id, $event->primaryPoster()->sole()->id);
        $this->assertCount(4, $disk->allFiles('event-posters'));
        $this->assertSame($hashes, array_map(fn (string $path): string => hash_file('sha256', $disk->path($path)), $existing));

        $this->put(route('events.update', $event), [...$this->eventData(), 'title' => 'Changed'])->assertSessionHasNoErrors();
        $this->assertSame($hashes, array_map(fn (string $path): string => hash_file('sha256', $disk->path($path)), $existing));
    }

    public function test_legacy_poster_preview_falls_back_to_its_original_file(): void
    {
        $path = UploadedFile::fake()->image('legacy.png')->store('event-posters', 'local');
        $poster = EventPoster::factory()->primary()->create(['file_path' => $path]);

        $this->get(route('events.posters.show', [$poster->event, $poster, 'size' => 'thumbnail']))
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('events.posters.show', [$poster->event, $poster, 'size' => 'invitation']))->assertNotFound();
        $this->assertSame([$path], Storage::disk('local')->files('event-posters'));
    }

    public function test_database_rejects_a_second_primary_for_the_same_event(): void
    {
        $event = Event::factory()->create();
        EventPoster::factory()->for($event)->primary()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        EventPoster::factory()->for($event)->primary()->create();
    }

    private function eventData(): array
    {
        return ['title' => 'Forum', 'status' => Event::STATUS_DRAFT, 'start_date' => '2027-01-15', 'end_date' => '2027-01-16'];
    }

    /** @return array{EventDay, EventDay} */
    private function posterDays(Event $event): array
    {
        return [
            EventDay::factory()->for($event)->create(['day_number' => 1, 'date' => '2027-01-15', 'title' => 'Opening']),
            EventDay::factory()->for($event)->create(['day_number' => 2, 'date' => '2027-01-16', 'title' => 'Closing']),
        ];
    }

    /** @return list<string> */
    private function variants(EventPoster $poster): array
    {
        return array_map(fn (string $size): string => EventPoster::variantPath($poster->file_path, $size), ['thumbnail', 'detail', 'invitation']);
    }
}
