<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['events.view', 'events.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->givePermissionTo(['events.view', 'events.update']);

        return $user;
    }

    private function eventDay(): array
    {
        $event = Event::factory()->create([
            'start_date' => '2027-01-15',
            'end_date' => '2027-01-17',
        ]);

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        return [$event, $day];
    }

    private function scheduleData(array $overrides = []): array
    {
        return array_merge(
            [
                'title' => 'Opening Ceremony',
                'description' => 'Pembukaan event',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'pic' => 'Budi Santoso',
                'sort_order' => 1,
            ],
            $overrides,
        );
    }

    public function test_authorized_user_can_open_create_form(): void
    {
        [$event, $day] = $this->eventDay();

        $this->actingAs($this->authorizedUser())
            ->get(route('events.days.schedules.create', [$event, $day]))
            ->assertOk()
            ->assertSee('Tambah Rundown')
            ->assertSee('PIC Kegiatan')
            ->assertDontSee('name="location"', false)
            ->assertDontSee('name="is_active"', false);
    }

    public function test_authorized_user_can_create_schedule(): void
    {
        [$event, $day] = $this->eventDay();

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.schedules.store', [$event, $day]), $this->scheduleData([
                'schedule_form' => 'schedule-new-'.$day->id,
                'pic' => '  Budi Santoso  ',
            ]))
            ->assertRedirect(route('events.days.show', [$event, $day]).'#day-rundown');

        $this->assertDatabaseHas('event_schedules', [
            'event_day_id' => $day->id,
            'title' => 'Opening Ceremony',
            'sort_order' => 1,
            'pic' => 'Budi Santoso',
            'is_active' => true,
        ]);
    }

    public function test_schedule_title_is_required(): void
    {
        [$event, $day] = $this->eventDay();

        $this->actingAs($this->authorizedUser())
            ->post(
                route('events.days.schedules.store', [$event, $day]),
                $this->scheduleData([
                    'title' => '',
                ]),
            )
            ->assertSessionHasErrors('title');
    }

    public function test_event_page_displays_compact_day_summaries_without_schedule_forms(): void
    {
        $this->withoutVite();

        [$event, $day] = $this->eventDay();

        $anotherDay = EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => '2027-01-16',
        ]);

        $schedule = EventSchedule::factory()->create([
            'event_day_id' => $day->id,
            'title' => 'Opening Ceremony',
            'pic' => 'Panitia Acara',
        ]);

        $this->actingAs($this->authorizedUser())
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('1 kegiatan rundown')->assertSee('0 kegiatan rundown')->assertSee('Belum ada rundown')
            ->assertSee('href="'.route('events.days.show', [$event, $day]).'"', false)
            ->assertSee('href="'.route('events.days.show', [$event, $anotherDay]).'"', false)
            ->assertSee('href="'.route('events.days.edit', [$event, $day]).'"', false)
            ->assertSee('data-delete-url="'.route('events.days.destroy', [$event, $day]).'"', false)
            ->assertDontSee('action="'.route('events.days.schedules.store', [$event, $day]).'"', false)
            ->assertDontSee('action="'.route('events.days.schedules.store', [$event, $anotherDay]).'"', false)
            ->assertDontSee('action="'.route('events.days.schedules.update', [$event, $day, $schedule]).'"', false)
            ->assertDontSee('Opening Ceremony')->assertDontSee('Panitia Acara')
            ->assertDontSee('name="pic"', false)
            ->assertDontSee('name="location"', false)
            ->assertDontSee('name="is_active"', false)
            ->assertDontSee('<th>Status</th>', false)
            ->assertDontSee('href="'.route('events.days.schedules.create', [$event, $day]).'"', false)
            ->assertDontSee('href="'.route('events.days.schedules.edit', [$event, $day, $schedule]).'"', false);
    }

    public function test_inline_schedule_validation_returns_to_the_tab_and_preserves_only_the_submitted_row(): void
    {
        $this->withoutVite();

        [$event, $day] = $this->eventDay();

        EventSchedule::factory()->create([
            'event_day_id' => $day->id,
            'title' => 'Original activity',
        ]);

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => '2027-01-16',
        ]);

        $this->actingAs($this->authorizedUser())
            ->from(route('events.days.show', [$event, $day]))
            ->post(route('events.days.schedules.store', [$event, $day]), $this->scheduleData([
                'schedule_form' => 'schedule-new-'.$day->id,
                'title' => 'Unsaved activity',
                'pic' => 'Draft PIC',
                'end_time' => '08:00',
            ]))
            ->assertRedirect(route('events.days.show', [$event, $day]).'#day-rundown')
            ->assertSessionHasErrors(['end_time' => 'Jam selesai harus lebih besar dari jam mulai.'])
            ->assertSessionHasInput('title', 'Unsaved activity');

        $response = $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('events.days.show', [$event, $day]))
            ->assertOk()
            ->assertSee('value="Original activity"', false)
            ->assertSee('value="Unsaved activity"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'value="Unsaved activity"'));
        $this->assertSame(1, substr_count($response->getContent(), 'value="Draft PIC"'));
        preg_match_all('/<div class="invalid-feedback">(.*?)<\/div>/s', $response->getContent(), $feedback);

        $this->assertSame(['Jam selesai harus lebih besar dari jam mulai.'], array_map('trim', $feedback[1]));
        $this->assertDatabaseCount('event_schedules', 1);
    }

    public function test_view_only_user_sees_schedule_details_without_inline_forms(): void
    {
        $this->withoutVite();

        [$event, $day] = $this->eventDay();

        $schedule = EventSchedule::factory()->create([
            'event_day_id' => $day->id,
            'pic' => 'Panitia Acara',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('events.view');

        $this->actingAs($user)
            ->get(route('events.days.show', [$event, $day]))
            ->assertOk()
            ->assertSee($schedule->title)
            ->assertSee($schedule->pic)
            ->assertDontSee('<th>Status</th>', false)
            ->assertDontSee('name="schedule_form"', false)
            ->assertDontSee('action="'.route('events.days.schedules.store', [$event, $day]).'"', false);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        [$event, $day] = $this->eventDay();

        $this->actingAs($this->authorizedUser())
            ->post(
                route('events.days.schedules.store', [$event, $day]),
                $this->scheduleData([
                    'start_time' => '10:00',
                    'end_time' => '09:00',
                ]),
            )
            ->assertSessionHasErrors('end_time');
    }

    public function test_schedule_can_overlap_another_schedule(): void
    {
        [$event, $day] = $this->eventDay();

        EventSchedule::factory()->create([
            'event_day_id' => $day->id,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(
                route('events.days.schedules.store', [$event, $day]),
                $this->scheduleData([
                    'start_time' => '09:30',
                    'end_time' => '10:30',
                ]),
            )
            ->assertRedirect(route('events.days.show', [$event, $day]).'#day-rundown');

        $this->assertEquals(2, EventSchedule::where('event_day_id', $day->id)->count());
    }

    public function test_authorized_user_can_update_schedule(): void
    {
        [$event, $day] = $this->eventDay();

        $schedule = EventSchedule::factory()->inactive()->create([
            'event_day_id' => $day->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->put(
                route('events.days.schedules.update', [$event, $day, $schedule]),
                $this->scheduleData([
                    'title' => 'Updated Ceremony',
                    'sort_order' => 2,
                    'schedule_form' => 'schedule-'.$schedule->id,
                    'pic' => 'Rina Wati',
                ]),
            )
            ->assertRedirect(route('events.days.show', [$event, $day]).'#day-rundown');

        $this->assertDatabaseHas('event_schedules', [
            'id' => $schedule->id,
            'title' => 'Updated Ceremony',
            'sort_order' => 2,
            'pic' => 'Rina Wati',
            'is_active' => false,
        ]);
    }

    public function test_schedule_pic_is_optional(): void
    {
        [$event, $day] = $this->eventDay();

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.schedules.store', [$event, $day]), $this->scheduleData([
                'pic' => '   ',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('events.days.show', [$event, $day]).'#day-rundown');

        $this->assertDatabaseHas('event_schedules', [
            'event_day_id' => $day->id,
            'pic' => null,
            'is_active' => true,
        ]);
    }

    public function test_schedule_from_another_day_cannot_be_updated(): void
    {
        [$event, $day] = $this->eventDay();

        $anotherDay = EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => '2027-01-16',
        ]);

        $schedule = EventSchedule::factory()->create([
            'event_day_id' => $anotherDay->id,
        ]);

        $this->actingAs($this->authorizedUser())->put(route('events.days.schedules.update', [$event, $day, $schedule]), $this->scheduleData())->assertNotFound();
    }

    public function test_authorized_user_can_delete_schedule(): void
    {
        [$event, $day] = $this->eventDay();

        $schedule = EventSchedule::factory()->create([
            'event_day_id' => $day->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.days.schedules.destroy', [$event, $day, $schedule]))
            ->assertRedirect(route('events.days.show', [$event, $day]).'#day-rundown');

        $this->assertDatabaseMissing('event_schedules', [
            'id' => $schedule->id,
        ]);
    }

    public function test_user_without_permission_cannot_create_schedule(): void
    {
        [$event, $day] = $this->eventDay();

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('events.days.schedules.store', [$event, $day]), $this->scheduleData())->assertForbidden();
    }

    public function test_viewer_cannot_open_management_forms_update_or_delete_the_day_rundown(): void
    {
        [$event, $day] = $this->eventDay();
        $schedule = EventSchedule::factory()->for($day, 'eventDay')->create(['title' => 'Protected activity']);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('events.view');
        $this->actingAs($user);

        $this->get(route('events.days.schedules.create', [$event, $day]))->assertForbidden();
        $this->get(route('events.days.schedules.edit', [$event, $day, $schedule]))->assertForbidden();
        $this->put(route('events.days.schedules.update', [$event, $day, $schedule]), $this->scheduleData())->assertForbidden();
        $this->delete(route('events.days.schedules.destroy', [$event, $day, $schedule]))->assertForbidden();

        $this->assertDatabaseCount('event_schedules', 1);
        $this->assertDatabaseHas('event_schedules', ['id' => $schedule->id, 'title' => 'Protected activity']);
    }

    public function test_inactive_user_cannot_access_rundown(): void
    {
        [$event, $day] = $this->eventDay();

        $user = $this->authorizedUser();

        $user->is_active = false;
        $user->save();

        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($user)->get(route('events.days.schedules.create', [$event, $day]))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
