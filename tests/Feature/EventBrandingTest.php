<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EventBrandingTest extends TestCase
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

    public function test_event_forms_store_and_display_a_map_and_private_logo_and_keep_it_on_edit(): void
    {
        $this->get(route('events.create'))->assertOk()->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="google_maps_url"', false)->assertSee('name="logo"', false);
        $map = 'https://maps.google.com/?q=Gedung+Utama';
        $logo = UploadedFile::fake()->image('original.png', 100, 60);
        $data = [...$this->eventData(), 'google_maps_url' => $map];

        $response = $this->post(route('events.store'), [...$data, 'logo' => $logo]);
        $event = Event::query()->sole();
        $response->assertRedirect(route('events.show', $event));
        $this->assertSame($map, $event->google_maps_url);
        $this->assertStringStartsWith('event-logos/', $event->logo_path);
        Storage::disk('local')->assertExists($event->logo_path);

        $logoUrl = route('events.logo', $event);
        $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertContent(Storage::disk('local')->get($event->logo_path));
        $this->get(route('events.show', $event))->assertOk()->assertSee('href="'.$map.'"', false)
            ->assertSee('>Gedung Utama</a>', false)->assertSee('src="'.$logoUrl.'"', false);
        $this->get(route('events.edit', $event))->assertOk()->assertSee('src="'.$logoUrl.'"', false)
            ->assertSee('value="'.$map.'"', false);

        $this->put(route('events.update', $event), [...$data, 'google_maps_url' => 'http://example.test/map'])
            ->assertRedirect(route('events.show', $event));
        $this->assertSame($event->logo_path, $event->fresh()->logo_path);
        $this->assertSame('http://example.test/map', $event->fresh()->google_maps_url);

        $this->put(route('events.update', $event), [...$data, 'google_maps_url' => '', 'logo' => UploadedFile::fake()->image('replacement.jpg')])
            ->assertRedirect(route('events.show', $event));
        $updated = $event->fresh();
        $this->assertNull($updated->google_maps_url);
        $this->assertNotSame($event->logo_path, $updated->logo_path);
        Storage::disk('local')->assertMissing($event->logo_path)->assertExists($updated->logo_path);
        $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs(User::factory()->create(['is_active' => true]))->get($logoUrl)->assertForbidden();
    }

    #[TestWith(['example.test/map'])]
    #[TestWith(['ftp://example.test/map'])]
    #[TestWith(['javascript:alert(1)'])]
    public function test_maps_url_requires_valid_http_or_https(string $url): void
    {
        $this->post(route('events.store'), [...$this->eventData(), 'google_maps_url' => $url])
            ->assertSessionHasErrors('google_maps_url');
        $this->assertDatabaseCount('events', 0);
    }

    public function test_logo_validation_and_failed_event_update_leave_storage_consistent(): void
    {
        foreach ([UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'), UploadedFile::fake()->image('too-large.png')->size(2049)] as $logo) {
            $this->post(route('events.store'), [...$this->eventData(), 'logo' => $logo])->assertSessionHasErrors('logo');
        }

        $event = Event::factory()->create();
        EventRegistration::factory()->count(2)->for($event)->create();
        $this->get(route('events.show', $event))->assertOk()->assertSee('avatar avatar-lg bg-primary-lt', false);
        $this->get(route('events.logo', $event))->assertNotFound();

        $this->put(route('events.update', $event), [...$this->eventData(), 'capacity' => 1, 'logo' => UploadedFile::fake()->image('new.png')])
            ->assertSessionHasErrors('capacity');
        $this->assertNull($event->fresh()->logo_path);
        $this->assertSame([], Storage::disk('local')->allFiles('event-logos'));
    }

    private function eventData(): array
    {
        return ['title' => 'Forum', 'location' => 'Gedung Utama', 'status' => Event::STATUS_DRAFT,
            'start_date' => '2027-01-15', 'end_date' => '2027-01-16'];
    }
}
