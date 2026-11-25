<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventMedia;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventGalleryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->manager = User::factory()->create(['is_active' => true])->assignRole('event-admin');
        $this->actingAs($this->manager);
    }

    public function test_uploads_preview_download_and_delete_photos_and_video(): void
    {
        $event = Event::factory()->create();
        $page = route('events.show', $event);
        $store = route('events.gallery.store', $event);
        $this->get($page)->assertOk()->assertSee('Gallery')->assertSee('Belum ada dokumentasi.');

        $videoPath = base_path('tests/Browser/event-gallery-clip.webm');
        $video = new UploadedFile($videoPath, 'clip.webm', test: true);
        $this->post($store, ['title' => 'Forum <Utama>', 'description' => "Baris satu\nBaris dua", 'files' => [
            UploadedFile::fake()->image('first.jpg'), UploadedFile::fake()->image('second.png'), $video,
        ]])->assertRedirect($page.'#event-gallery')->assertSessionHasNoErrors();

        $this->assertCount(2, $event->media()->where('media_type', 'image')->get());
        $clip = $event->media()->where('media_type', 'video')->sole();
        $photo = $event->media()->where('original_filename', 'first.jpg')->sole();
        $this->assertSame($this->manager->id, $photo->uploader->id);
        $this->assertSame(filesize($videoPath), $clip->file_size);
        $this->assertSame("Baris satu\nBaris dua", $clip->description);
        Storage::disk('local')->assertExists($event->media()->pluck('file_path')->all());

        $this->get($page.'?media_type=video')->assertOk()->assertSee('Forum &lt;Utama&gt;', false)
            ->assertSee('<video ', false)->assertDontSee(route('events.gallery.show', [$event, $photo]));
        $this->get($page.'?media_type=image')->assertOk()->assertSee(route('events.gallery.show', [$event, $photo]))
            ->assertDontSee(route('events.gallery.show', [$event, $clip]));
        $this->get(route('events.gallery.show', [$event, $clip]))->assertOk()->assertHeader('Content-Type', 'video/webm');
        $this->get(route('events.gallery.show', [$event, $clip]), ['Range' => 'bytes=0-15'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-15/'.filesize($videoPath));
        $this->get(route('events.gallery.show', [$event, $photo]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('events.gallery.download', [$event, $photo]))->assertOk()->assertDownload('first.jpg');
        $this->delete(route('events.gallery.destroy', [$event, $photo]))->assertRedirect($page.'#event-gallery');
        $this->assertModelMissing($photo);
        Storage::disk('local')->assertMissing($photo->file_path);
    }

    public function test_scoped_rbac_and_validation_block_access_and_unsupported_uploads(): void
    {
        $event = Event::factory()->create();
        $image = UploadedFile::fake()->image('photo.png');
        $wrongType = UploadedFile::fake()->createWithContent('spoofed.jpg', '<?php echo "not an image";');
        $upload = new UploadedFile($wrongType->getPathname(), 'spoofed.jpg', 'image/jpeg', test: true);
        $this->post(route('events.gallery.store', $event), ['files' => [$image, $upload]])->assertSessionHasErrors('files.1');
        config(['event_assets.gallery_video_max_kb' => 1]);
        $this->post(route('events.gallery.store', $event), ['files' => [UploadedFile::fake()->create('large.webm', 2, 'video/webm')]])
            ->assertSessionHasErrors('files.0');
        $this->assertSame([], Storage::disk('local')->files('event-gallery'));

        $this->post(route('events.gallery.store', $event), ['files' => [$image]])->assertSessionHasNoErrors();
        $media = $event->media()->sole();
        $preview = route('events.gallery.show', [$event, $media]);
        $download = route('events.gallery.download', [$event, $media]);
        $delete = route('events.gallery.destroy', [$event, $media]);
        $this->get(route('events.gallery.show', [Event::factory()->create(), $media]))->assertNotFound();

        $noAccess = User::factory()->create(['is_active' => true]);
        $this->actingAs($noAccess)->get($preview)->assertForbidden();
        $this->get($download)->assertForbidden();

        $noAccess->givePermissionTo('events.view');
        $this->get($preview)->assertOk();
        $this->get($download)->assertOk();
        $this->delete($delete)->assertForbidden();
        $this->post(route('events.gallery.store', $event), ['files' => [$image]])->assertForbidden();
        Storage::disk('local')->assertExists($media->file_path);
    }

    public function test_gallery_paginates_without_rendering_every_item(): void
    {
        $event = Event::factory()->create();
        $media = EventMedia::factory()->for($event)->count(25)->create();
        foreach ($media as $index => $item) {
            $item->update(['title' => "Dokumentasi #{$index}"]);
        }
        $page = route('events.show', $event);
        $this->get($page)->assertOk()->assertSee('Dokumentasi #24')->assertDontSee('Dokumentasi #0')
            ->assertSee('gallery_page=2')->assertSee('#event-gallery');
        $this->get($page.'?gallery_page=3&media_type=image')->assertOk()
            ->assertSee('Dokumentasi #0')->assertDontSee('Dokumentasi #24')->assertSee('media_type=image');
    }
}
