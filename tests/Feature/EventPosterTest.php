<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPoster;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->get(route('events.edit', $event))->assertOk()->assertSee($url)->assertSee('name="posters[]"', false);
        $this->get(route('events.posters.show', [Event::factory()->create(), $primary]))->assertNotFound();
        $this->actingAs(User::factory()->create(['is_active' => true]))->get($url)->assertForbidden();
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
        $this->assertCount(6, Storage::disk('local')->files('event-posters'));

        $this->put($edit, [...$this->eventData(), 'poster_remove' => [$third->id]])->assertSessionHasNoErrors();
        $this->assertSame($second->id, $event->primaryPoster()->sole()->id);
        Storage::disk('local')->assertMissing([$third->file_path, EventPoster::invitationPath($third->file_path)]);
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
        $this->assertSame([$poster->file_path, EventPoster::invitationPath($poster->file_path)], Storage::disk('local')->files('event-posters'));
        $this->assertTrue($poster->fresh()->is_primary);

        $this->actingAs(User::factory()->create(['is_active' => true])->assignRole('super-admin'))
            ->delete(route('events.destroy', $event))->assertRedirect(route('events.index'));
        $this->assertDatabaseCount('event_posters', 0);
        Storage::disk('local')->assertMissing([$poster->file_path, EventPoster::invitationPath($poster->file_path)]);
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
}
