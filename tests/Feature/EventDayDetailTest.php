<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventSchedule;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventDayDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['events.view', 'events.update', 'attendance.scan', 'attendance.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Queue::fake();
    }

    public function test_event_detail_links_to_each_day_without_requiring_edit_permission(): void
    {
        [$event, $day] = $this->eventDay();
        $otherDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);

        $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk()
            ->assertSee('href="'.route('events.days.show', [$event, $day]).'"', false)
            ->assertSee('href="'.route('events.days.show', [$event, $otherDay]).'"', false);
    }

    public function test_day_detail_uses_the_shared_layout_and_only_the_selected_days_rundown(): void
    {
        [$event, $day] = $this->eventDay();
        $otherDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        EventSchedule::factory()->for($day, 'eventDay')->create([
            'title' => 'Selected day briefing', 'description' => 'Selected rundown description',
            'start_time' => '08:00:00', 'end_time' => '09:00:00', 'pic' => 'Selected PIC',
        ]);
        EventSchedule::factory()->for($otherDay, 'eventDay')->create(['title' => 'Other day must not appear']);

        $this->actingAs($this->viewer())->get(route('events.days.show', [$event, $day]))->assertOk()
            ->assertSee($event->title)->assertSee($day->date->format('d M Y'))->assertSee('Published')
            ->assertSee('aria-label="Main navigation"', false)->assertSee('Kembali ke Event')
            ->assertSee('Selected day briefing')->assertSee('Selected rundown description')
            ->assertSee('08:00')->assertSee('09:00')->assertSee('Selected PIC')
            ->assertSee('id="day-schedules-'.$day->id.'" data-datatable', false)
            ->assertDontSee('Other day must not appear')
            ->assertDontSee('href="#day-scanner"', false)
            ->assertDontSee('id="deleteScheduleModal"', false);
    }

    public function test_event_day_summaries_use_schedule_ranges_and_active_attendance_without_loading_schedules(): void
    {
        [$event, $day] = $this->eventDay();
        $emptyDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        EventSchedule::factory()->for($day, 'eventDay')->create(['start_time' => '08:00:00', 'end_time' => '09:00:00']);
        EventSchedule::factory()->for($day, 'eventDay')->create(['start_time' => '13:00:00', 'end_time' => '17:00:00']);
        $registrations = EventRegistration::factory()->for($event)->count(2)->create();
        $cancelled = EventRegistration::factory()->for($event)->cancelled()->create();
        Attendance::factory()->create(['event_day_id' => $day->id, 'event_registration_id' => $registrations[0]->id]);
        Attendance::factory()->create(['event_day_id' => $day->id, 'event_registration_id' => $cancelled->id]);

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk()
            ->assertSee('2 kegiatan rundown')->assertSee('08:00 - 17:00')
            ->assertSee('1 / 2 peserta hadir')->assertSee('0 / 2 peserta hadir')->assertSee('Belum ada rundown')
            ->assertDontSee('name="schedule_form"', false)->assertDontSee('id="deleteScheduleModal"', false)
            ->assertDontSee('href="'.route('events.days.edit', [$event, $day]).'"', false);

        $days = $response->viewData('event')->days->keyBy('id');
        $this->assertSame(2, $days[$day->id]->schedules_count);
        $this->assertSame(1, $days[$day->id]->checked_in_count);
        $this->assertSame(0, $days[$emptyDay->id]->schedules_count);
        $this->assertFalse($days[$day->id]->relationLoaded('schedules'));
    }

    public function test_day_summary_query_count_does_not_grow_with_the_number_of_days(): void
    {
        [$event] = $this->eventDay();
        $this->actingAs($this->viewer());
        $this->get(route('events.show', $event))->assertOk();
        DB::enableQueryLog();

        try {
            DB::flushQueryLog();
            $this->get(route('events.show', $event))->assertOk();
            $singleDayQueryCount = count(DB::getQueryLog());
            EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
            DB::flushQueryLog();

            $this->get(route('events.show', $event))->assertOk();

            $this->assertSame($singleDayQueryCount, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_fixed_day_checkin_rejects_changed_event_day_or_signature_and_records_only_the_correct_context(): void
    {
        [$event, $day] = $this->eventDay();
        $this->travelTo($day->date->copy()->setTime(9, 30));
        $registration = EventRegistration::factory()->for($event)->create();
        $otherDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        $otherEvent = Event::factory()->published()->create();
        $this->actingAs($this->viewer(['attendance.scan']));
        $url = $this->get(route('events.days.show', [$event, $day]))->assertOk()->viewData('scannerData')['checkinUrl'];
        $payload = ['event_id' => $event->id, 'event_day_id' => $day->id, 'qr_code' => 'RA1:'.$registration->uuid];

        $this->postJson($url, [...$payload, 'event_id' => $otherEvent->id])
            ->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->postJson($url, [...$payload, 'event_day_id' => $otherDay->id])
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->postJson(str_replace('day='.$day->id, 'day='.$otherDay->id, $url), $payload)->assertForbidden();
        $this->assertDatabaseCount('attendances', 0);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('summary.checked_in', 1);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', ['event_registration_id' => $registration->id, 'event_day_id' => $day->id]);
    }

    public function test_day_detail_enforces_event_ownership_and_view_permission(): void
    {
        [$event, $day] = $this->eventDay();
        $otherEvent = Event::factory()->create();
        $this->get(route('events.days.show', [$event, $day]))->assertRedirect(route('login'));
        $this->actingAs($this->viewer())->get(route('events.days.show', [$otherEvent, $day]))->assertNotFound();

        $scannerOnly = User::factory()->create(['is_active' => true]);
        $scannerOnly->givePermissionTo('attendance.scan');
        $this->actingAs($scannerOnly)->get(route('events.days.show', [$event, $day]))->assertForbidden();

        $inactive = $this->viewer();
        $inactive->is_active = false;
        $inactive->save();
        $this->actingAs($inactive)->get(route('events.days.show', [$event, $day]))->assertRedirect(route('login'));
    }

    public function test_schedule_management_controls_keep_existing_routes_and_authorization(): void
    {
        [$event, $day] = $this->eventDay();
        $schedule = EventSchedule::factory()->for($day, 'eventDay')->create();

        $this->actingAs($this->viewer(['events.update']))->get(route('events.days.show', [$event, $day]))
            ->assertOk()->assertSee('href="'.route('events.days.edit', [$event, $day]).'"', false)
            ->assertSee('action="'.route('events.days.schedules.store', [$event, $day]).'"', false)
            ->assertSee('action="'.route('events.days.schedules.update', [$event, $day, $schedule]).'"', false)
            ->assertSee('data-delete-url="'.route('events.days.schedules.destroy', [$event, $day, $schedule]).'"', false)
            ->assertSee('id="deleteScheduleForm"', false);
    }

    public function test_day_detail_route_does_not_shadow_the_existing_create_page(): void
    {
        [$event] = $this->eventDay();

        $this->actingAs($this->viewer(['events.update']))->get(route('events.days.create', $event))->assertOk();
    }

    public function test_embedded_scanner_uses_fixed_context_without_event_or_day_selectors(): void
    {
        [$event, $day] = $this->eventDay();
        EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        $response = $this->actingAs($this->viewer(['attendance.scan', 'attendance.view']))
            ->get(route('events.days.show', [$event, $day]))->assertOk()
            ->assertSee('href="#day-scanner"', false)->assertSee('href="#day-attendees"', false)
            ->assertSee('data-fixed-event-id="'.$event->id.'"', false)
            ->assertSee('data-fixed-day-id="'.$day->id.'"', false);

        $data = $response->viewData('scannerData');
        $this->assertSame([$day->id], array_column($data['events'][0]['days'], 'id'));
        $this->assertSame($day->id, $data['fixedDay']->id);
        $this->assertStringContainsString('day='.$day->id, $data['validationUrl']);
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@id="qr-reader"]')->length);
        $this->assertSame(0, $xpath->query('//select[@id="scanner-event" or @id="scanner-day"]')->length);
        $this->assertSame(1, $xpath->query('//select[@id="scanner-camera"]')->length);
    }

    #[TestWith(['draft', true, false])]
    #[TestWith(['closed', true, false])]
    #[TestWith(['cancelled', true, false])]
    #[TestWith(['published', false, false])]
    #[TestWith(['published', true, true])]
    public function test_ineligible_days_keep_rundown_available_without_starting_a_scanner(string $status, bool $isActive, bool $outsideRange): void
    {
        [$event, $day] = $this->eventDay();
        $event->update(['status' => $status]);
        $day->update(['is_active' => $isActive, 'date' => $outsideRange ? '2027-01-20' : '2027-01-16']);

        $response = $this->actingAs($this->viewer(['attendance.scan']))->get(route('events.days.show', [$event, $day]))
            ->assertOk()->assertSee('Rundown')->assertDontSee('data-attendance-scanner', false);

        $this->assertNull($response->viewData('scannerData'));
    }

    #[TestWith([0])]
    #[TestWith([3])]
    public function test_summary_counts_active_registrations_and_starts_with_zero_persisted_attendance(int $activeCount): void
    {
        [$event, $day] = $this->eventDay();
        EventRegistration::factory()->for($event)->count($activeCount)->create();
        EventRegistration::factory()->for($event)->cancelled()->count(2)->create();
        EventRegistration::factory()->count(2)->create();

        $response = $this->actingAs($this->viewer(['attendance.view']))->get(route('events.days.show', [$event, $day]))
            ->assertOk()->assertSee('data-attendance-table', false)
            ->assertSee('0%')->assertDontSee('Pencatatan kehadiran belum tersedia.')
            ->assertDontSee('Konfirmasi Check-in');

        $this->assertSame([
            'active_registrations' => $activeCount,
            'checked_in' => 0, 'not_checked_in' => $activeCount, 'percentage' => 0.0,
        ], $response->viewData('attendanceSummary'));
    }

    public function test_empty_day_has_a_rundown_empty_state_without_invalid_datatables_rows(): void
    {
        [$event, $day] = $this->eventDay();

        $this->actingAs($this->viewer())->get(route('events.days.show', [$event, $day]))->assertOk()
            ->assertSee('Belum ada rundown kegiatan.')->assertDontSee('data-datatable', false);
    }

    public function test_signed_day_context_rejects_a_different_day_in_the_same_event(): void
    {
        [$event, $day] = $this->eventDay();
        $otherDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        $registration = EventRegistration::factory()->for($event)->create();
        $this->actingAs($this->viewer(['attendance.scan']));
        $url = $this->get(route('events.days.show', [$event, $day]))->viewData('scannerData')['validationUrl'];

        $this->postJson($url, ['event_id' => $event->id, 'event_day_id' => $otherDay->id, 'qr_code' => 'RA1:'.$registration->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id')->assertJsonMissingPath('data');
        $this->postJson(route('attendance.scanner.validate', ['day' => $day->id]), [])
            ->assertForbidden()->assertJsonMissingPath('data');
        $this->postJson(str_replace('day='.$day->id, 'day='.$otherDay->id, $url), [])
            ->assertForbidden()->assertJsonMissingPath('data');
    }

    public function test_day_scanner_rejects_cross_event_qr_and_monitoring_roles_cannot_validate(): void
    {
        [$event, $day] = $this->eventDay();
        $otherRegistration = EventRegistration::factory()->create();
        $this->actingAs($this->viewer(['attendance.scan']));
        $url = $this->get(route('events.days.show', [$event, $day]))->viewData('scannerData')['validationUrl'];

        $this->postJson($url, ['event_id' => $event->id, 'event_day_id' => $day->id, 'qr_code' => 'RA1:'.$otherRegistration->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('qr_code')->assertJsonMissingPath('data');
        $this->actingAs($this->viewer(['attendance.view']))->postJson($url, [])->assertForbidden();
    }

    public function test_day_validation_is_read_only_and_preserves_registration_uuid_and_employee_nip(): void
    {
        [$event, $day] = $this->eventDay();
        $employee = Employee::factory()->create(['nip' => '1234567890123456']);
        $registration = EventRegistration::factory()->for($event)->for($employee)->create();
        $registrationAttributes = $registration->fresh()->getAttributes();
        $employeeAttributes = $employee->fresh()->getAttributes();
        $this->actingAs($this->viewer(['attendance.scan']));
        $url = $this->get(route('events.days.show', [$event, $day]))->viewData('scannerData')['validationUrl'];
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson($url, ['event_id' => $event->id, 'event_day_id' => $day->id, 'qr_code' => 'RA1:'.$registration->uuid])
                ->assertOk()->assertJsonPath('data.participant_name', $employee->name);
        }

        $this->assertSame([], $writes);
        $this->assertSame($registrationAttributes, $registration->fresh()->getAttributes());
        $this->assertSame($employeeAttributes, $employee->fresh()->getAttributes());
        $this->assertDatabaseCount('event_registration_notifications', 0);
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function viewer(array $permissions = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['events.view', ...$permissions]);

        return $user;
    }

    /**
     * @return array{Event, EventDay}
     */
    private function eventDay(): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        $day = EventDay::factory()->for($event)->create(['date' => '2027-01-16']);

        return [$event, $day];
    }
}
