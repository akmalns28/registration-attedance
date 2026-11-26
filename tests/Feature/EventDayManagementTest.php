<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventDayManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('events.view', 'web');
        Permission::findOrCreate('events.create', 'web');
        Permission::findOrCreate('events.update', 'web');
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $user->givePermissionTo(['events.view', 'events.create', 'events.update']);

        return $user;
    }

    private function event(): Event
    {
        return Event::factory()->create([
            'start_date' => '2027-01-15',
            'end_date' => '2027-01-17',
        ]);
    }

    public function test_create_form_defaults_to_automatic_days_and_manual_creation_cannot_duplicate_them(): void
    {
        $this->actingAs($this->authorizedUser())->get(route('events.create'))->assertOk()
            ->assertSee('Buat Event Day otomatis')
            ->assertSee('type="checkbox" name="auto_create_event_days" value="1"', false)
            ->assertSee('checked>', false);

        $response = $this->post(route('events.store'), [...$this->newEventData(), 'auto_create_event_days' => '1']);
        $event = Event::query()->sole();
        $response->assertRedirect(route('events.show', $event));

        $days = $event->days()->get();
        $this->assertSame(['2027-12-10', '2027-12-11', '2027-12-12'], $days->map(fn (EventDay $day): string => $day->date->toDateString())->all());
        $this->assertSame([1, 2, 3], $days->pluck('day_number')->all());
        $this->assertSame([null, null, null], $days->pluck('title')->all());
        $this->assertSame([true, true, true], $days->pluck('is_active')->all());

        $this->post(route('events.days.store', $event), ['date' => '2027-12-11', 'is_active' => true])->assertSessionHasErrors('date');
        $this->assertSame(3, $event->days()->count());
    }

    #[TestWith(['0'])]
    #[TestWith([null])]
    public function test_manual_mode_leaves_days_empty_until_an_operator_creates_one(?string $selection): void
    {
        $payload = $this->newEventData();
        if ($selection !== null) {
            $payload['auto_create_event_days'] = $selection;
        }
        $response = $this->actingAs($this->authorizedUser())->post(route('events.store'), $payload);
        $event = Event::query()->sole();
        $response->assertRedirect(route('events.show', $event));
        $this->assertDatabaseCount('event_days', 0);

        $this->post(route('events.days.store', $event), ['date' => '2027-12-11', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('event_days', ['event_id' => $event->id, 'date' => '2027-12-11', 'day_number' => 2]);
    }

    public function test_declined_automatic_days_stay_unchecked_when_validation_redirects(): void
    {
        $this->actingAs($this->authorizedUser())->from(route('events.create'))
            ->post(route('events.store'), [...$this->newEventData(), 'end_date' => '2027-12-09', 'auto_create_event_days' => '0'])
            ->assertRedirect(route('events.create'))->assertSessionHasErrors('end_date');

        $this->get(route('events.create'))->assertOk()->assertDontSee('checked>', false);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_updating_an_event_ignores_automatic_generation_and_preserves_existing_days_and_attendance(): void
    {
        $event = $this->event();
        $day = EventDay::factory()->for($event)->create(['title' => 'Original Day']);
        $attendance = Attendance::factory()->for($day)->create();

        $this->actingAs($this->authorizedUser())->get(route('events.edit', $event))->assertOk()->assertDontSee('Buat Event Day otomatis');
        $this->put(route('events.update', $event), [...$this->newEventData(), 'start_date' => '2027-01-15',
            'end_date' => '2027-01-18', 'auto_create_event_days' => '1'])->assertRedirect(route('events.show', $event));

        $this->assertSame($day->id, $event->days()->sole()->id);
        $this->assertSame('Original Day', $day->fresh()->title);
        $this->assertDatabaseHas('attendances', ['id' => $attendance->id, 'event_day_id' => $day->id]);
    }

    public function test_automatic_generation_rolls_back_the_event_and_days_if_a_later_batch_fails(): void
    {
        $insertions = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$insertions): void {
            if (str_starts_with($query, 'insert into "event_days"') && ++$insertions === 2) {
                throw new RuntimeException('Simulated Event Day insertion failure.');
            }
        });

        $this->actingAs($this->authorizedUser())->post(route('events.store'), [...$this->newEventData(),
            'start_date' => '2027-01-01', 'end_date' => '2027-08-01', 'auto_create_event_days' => '1'])->assertInternalServerError();

        $this->assertSame(2, $insertions);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_days', 0);
    }

    private function newEventData(): array
    {
        return ['title' => 'Forum Desember', 'start_date' => '2027-12-10', 'end_date' => '2027-12-12', 'status' => Event::STATUS_DRAFT];
    }

    public function test_event_forms_save_optional_day_times_and_apply_the_default_dress_code(): void
    {
        $this->withoutVite()->actingAs($this->authorizedUser());
        $this->get(route('events.create'))->assertOk()->assertSee('name="dress_code"', false);
        $data = $this->newEventData();
        $this->post(route('events.store'), [...$data, 'dress_code' => 'Batik'])->assertSessionHasNoErrors();
        $event = Event::query()->sole();
        $this->get(route('events.edit', $event))->assertOk()->assertSee('value="Batik"', false);
        $this->get(route('events.days.create', $event))->assertOk()->assertSee('type="time"', false);

        $this->post(route('events.days.store', $event), [
            'date' => '2027-12-10', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->post(route('events.days.store', $event), [
            'date' => '2027-12-11', 'start_time' => '09:30', 'end_time' => '16:00', 'dress_code' => 'Pakaian olahraga', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->post(route('events.days.store', $event), [
            'date' => '2027-12-12', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        [$first, $second, $third] = $event->days()->get();

        $this->get(route('events.show', $event))->assertOk()
            ->assertSee('08.00–17.00 WIB')->assertSee('09.30–16.00 WIB')
            ->assertSee('Batik')->assertSee('Pakaian olahraga');
        $this->get(route('events.days.show', [$event, $first]))->assertOk()->assertSee('Batik');
        $this->get(route('events.days.show', [$event, $second]))->assertOk()->assertSee('Pakaian olahraga');
        $this->get(route('events.days.edit', [$event, $second]))->assertOk()->assertSee('value="09:30"', false);
        $this->assertNull($third->start_time);
        $this->assertNull($third->end_time);
        $this->assertNull($third->dress_code);

        $this->put(route('events.days.update', [$event, $second]), [
            'date' => '2027-12-11', 'start_time' => '', 'end_time' => '16:00', 'dress_code' => '', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->get(route('events.days.show', [$event, $second]))->assertOk()
            ->assertSee('Selesai 16.00 WIB')->assertSee('Batik')->assertDontSee('Pakaian olahraga');

        $this->put(route('events.update', $event), [...$data, 'dress_code' => ''])->assertSessionHasNoErrors();
        $this->assertNull($event->fresh()->dress_code);
        $this->get(route('events.days.show', [$event, $third]))->assertOk()
            ->assertDontSee('Dress Code')->assertDontSee('Jam Pelaksanaan');
    }

    #[TestWith(['09:00', '09:00', 'end_time'])]
    #[TestWith(['17:00', '08:00', 'end_time'])]
    #[TestWith(['25:00', null, 'start_time'])]
    #[TestWith([null, '9:00', 'end_time'])]
    public function test_event_day_time_validation_rejects_invalid_or_unordered_times(?string $start, ?string $end, string $error): void
    {
        $this->actingAs($this->authorizedUser())->post(route('events.days.store', $this->event()), [
            'date' => '2027-01-15', 'start_time' => $start, 'end_time' => $end, 'is_active' => true,
        ])->assertSessionHasErrors($error);

        $this->assertDatabaseCount('event_days', 0);
    }

    public function test_dress_code_length_is_validated_for_events_and_days(): void
    {
        $tooLong = str_repeat('x', 151);
        $this->actingAs($this->authorizedUser())
            ->post(route('events.store'), [...$this->newEventData(), 'dress_code' => $tooLong])
            ->assertSessionHasErrors('dress_code');
        $this->post(route('events.days.store', $this->event()), [
            'date' => '2027-01-15', 'dress_code' => $tooLong, 'is_active' => true,
        ])->assertSessionHasErrors('dress_code');
    }

    public function test_authorized_user_can_create_event_day(): void
    {
        $event = $this->event();

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-16',
                'title' => 'Main Event',
                'is_active' => true,
            ])
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseHas('event_days', [
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => '2027-01-16',
        ]);
    }

    public function test_event_day_date_must_be_within_event_range(): void
    {
        $event = $this->event();

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-18',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('date');
    }

    public function test_duplicate_event_day_date_is_rejected(): void
    {
        $event = $this->event();

        EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-15',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('date');
    }

    public function test_authorized_user_can_update_event_day(): void
    {
        $event = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2027-01-15',
        ]);

        $this->actingAs($this->authorizedUser())
            ->put(route('events.days.update', [$event, $day]), [
                'date' => '2027-01-16',
                'title' => 'Updated Day',
                'is_active' => true,
            ])
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseHas('event_days', [
            'id' => $day->id,
            'day_number' => 2,
            'date' => '2027-01-16',
            'title' => 'Updated Day',
        ]);
    }

    public function test_event_day_from_another_event_cannot_be_updated(): void
    {
        $event = $this->event();
        $otherEvent = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $otherEvent->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->put(route('events.days.update', [$event, $day]), [
                'date' => '2027-01-16',
                'is_active' => true,
            ])
            ->assertNotFound();
    }

    public function test_event_day_without_schedules_can_be_deleted(): void
    {
        $event = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.days.destroy', [$event, $day]))
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseMissing('event_days', [
            'id' => $day->id,
        ]);
    }

    public function test_event_day_with_schedules_cannot_be_deleted(): void
    {
        $event = $this->event();

        $day = EventDay::factory()->create([
            'event_id' => $event->id,
        ]);

        EventSchedule::factory()->create([
            'event_day_id' => $day->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.days.destroy', [$event, $day]))
            ->assertRedirect(route('events.show', $event).'#event-days');

        $this->assertDatabaseHas('event_days', [
            'id' => $day->id,
        ]);
    }

    public function test_user_without_permission_cannot_create_event_day(): void
    {
        $event = $this->event();

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('events.days.store', $event), [
                'date' => '2027-01-15',
                'is_active' => true,
            ])
            ->assertForbidden();
    }
}
