<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceCheckinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['attendance.scan', 'attendance.view', 'events.view', 'events.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->travelTo(Carbon::parse('2027-01-16 09:30:00', 'Asia/Jakarta'));
        Queue::fake();
    }

    #[TestWith(['employee'])]
    #[TestWith(['external'])]
    public function test_only_confirmed_checkin_persists_the_correct_participant_and_operator(string $type): void
    {
        [$event, $day, $registration] = $this->registration($type);
        $operator = $this->staff();
        $attributes = $registration->fresh()->getAttributes();
        $payload = $this->payload($event, $day, $registration);

        $this->actingAs($operator)->postJson(route('attendance.scanner.validate'), $payload)->assertOk();
        $this->assertDatabaseCount('attendances', 0);
        $response = $this->postJson(route('attendance.check-in'), $payload)->assertCreated()
            ->assertJsonPath('success', true)->assertJsonPath('created', true)
            ->assertJsonPath('data.participant_name', $registration->participant_name)
            ->assertJsonPath('data.participant_type', $type)->assertJsonPath('data.attendance_status', 'present')
            ->assertJsonPath('data.checkin_time', '09:30:00')->assertJsonPath('data.operator_name', $operator->name)
            ->assertJsonPath('summary.checked_in', 1)->assertJsonPath('summary.not_checked_in', 0)
            ->assertJsonMissingPath('data.nip')->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.phone');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('attendances', [
            'event_registration_id' => $registration->id, 'event_day_id' => $day->id, 'checked_in_by' => $operator->id,
        ]);
        $this->assertSame($attributes, $registration->fresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    public function test_duplicate_confirmation_preserves_the_first_attendance_time_and_operator(): void
    {
        [$event, $day, $registration] = $this->registration();
        $payload = $this->payload($event, $day, $registration);
        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $payload)->assertCreated();
        $original = Attendance::query()->sole()->getAttributes();
        $this->travel(20)->minutes();

        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $payload)
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('message', 'Peserta sudah check-in.')
            ->assertJsonPath('data.checkin_time', '09:30:00');

        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame($original, Attendance::query()->sole()->getAttributes());
    }

    public function test_same_registration_can_check_in_on_another_eligible_event_day(): void
    {
        [$event, $day, $registration] = $this->registration();
        $secondDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))->assertCreated();
        $this->travelTo(Carbon::parse('2027-01-17 09:30:00', 'Asia/Jakarta'));

        $this->postJson(route('attendance.check-in'), $this->payload($event, $secondDay, $registration))->assertCreated();

        $this->assertDatabaseCount('attendances', 2);
    }

    #[TestWith([''])]
    #[TestWith(['invalid'])]
    #[TestWith(['RA2:01a1cf4f-9c62-721a-9b5a-d478aea18229'])]
    #[TestWith(['RA1:not-a-uuid'])]
    public function test_invalid_payloads_never_return_success_or_write_attendance(string $qrCode): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), [
            ...$this->payload($event, $day, $registration), 'qr_code' => $qrCode,
        ])->assertUnprocessable()->assertJsonPath('success', false)->assertJsonMissingPath('data');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_unknown_registration_is_rejected(): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), [
            ...$this->payload($event, $day, $registration), 'qr_code' => 'RA1:'.Str::uuid7(),
        ])->assertUnprocessable()->assertJsonValidationErrors('qr_code');
        $this->assertDatabaseCount('attendances', 0);
    }

    #[TestWith(['cancelled'])]
    #[TestWith(['inactive_participant'])]
    public function test_ineligible_registration_is_rejected_without_mutation(string $reason): void
    {
        [$event, $day, $registration] = $this->registration();
        if ($reason === 'cancelled') {
            $registration->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        } else {
            $registration->employee->update(['is_active' => false]);
        }
        $attributes = $registration->fresh()->getAttributes();

        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonPath('success', false);

        $this->assertSame($attributes, $registration->fresh()->getAttributes());
        $this->assertDatabaseCount('attendances', 0);
    }

    #[TestWith(['draft'])]
    #[TestWith(['closed'])]
    #[TestWith(['cancelled'])]
    public function test_ineligible_event_status_is_rechecked(string $status): void
    {
        [$event, $day, $registration] = $this->registration();
        $event->update(['status' => $status]);

        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_cross_event_registration_and_event_day_are_rejected(): void
    {
        [$event, $day, $registration] = $this->registration();
        [, $otherDay, $otherRegistration] = $this->registration();
        $this->actingAs($this->staff());

        $this->postJson(route('attendance.check-in'), $this->payload($event, $day, $otherRegistration))
            ->assertUnprocessable()->assertJsonValidationErrors('qr_code');
        $this->postJson(route('attendance.check-in'), $this->payload($event, $otherDay, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->assertDatabaseCount('attendances', 0);
    }

    #[TestWith(['2027-01-15'])]
    #[TestWith(['2027-01-17'])]
    public function test_date_restriction_uses_server_timezone_and_ignores_client_date(string $date): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->travelTo(Carbon::parse($date.' 09:30:00', 'Asia/Jakarta'));

        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), [
            ...$this->payload($event, $day, $registration), 'client_date' => '2027-01-16',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_day_id')
            ->assertJsonPath('message', 'Check-in hanya tersedia pada tanggal 16/01/2027 (Asia/Jakarta).');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_jakarta_date_is_used_even_when_utc_is_the_previous_day(): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->travelTo(Carbon::parse('2027-01-15 18:00:00', 'UTC'));

        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))->assertCreated();
    }

    public function test_authentication_active_user_and_scan_permission_are_required(): void
    {
        [$event, $day, $registration] = $this->registration();
        $payload = $this->payload($event, $day, $registration);
        $this->postJson(route('attendance.check-in'), $payload)->assertUnauthorized()->assertJsonPath('success', false);
        $viewer = $this->staff(false);
        $this->actingAs($viewer)->postJson(route('attendance.check-in'), $payload)->assertForbidden();
        $inactive = $this->staff();
        $inactive->is_active = false;
        $inactive->save();
        $this->actingAs($inactive)->postJson(route('attendance.check-in'), $payload)->assertForbidden();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_csrf_is_enforced_on_confirmation(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), [])->assertStatus(419)->assertJsonPath('success', false);
    }

    public function test_each_scanner_entry_uses_the_same_checkin_endpoint_with_bound_context(): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->actingAs($this->staff());
        $generic = $this->get(route('attendance.scanner'))->assertOk()->viewData('checkinUrl');
        $specific = $this->get(route('events.scanner', $event))->assertOk()->viewData('checkinUrl');
        $daySpecific = $this->get(route('events.days.show', [$event, $day]))->assertOk()->viewData('scannerData')['checkinUrl'];
        foreach ([$generic, $specific, $daySpecific] as $url) {
            $this->assertSame('/attendance/check-in', parse_url($url, PHP_URL_PATH));
            $this->postJson($url, $this->payload($event, $day, $registration))->assertSuccessful()->assertJsonPath('success', true);
        }
        $this->assertDatabaseCount('attendances', 1);
        $otherDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        $this->postJson($daySpecific, $this->payload($event, $otherDay, $registration))->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->postJson(str_replace('day='.$day->id, 'day='.$otherDay->id, $daySpecific), [])->assertForbidden();
    }

    public function test_attendance_is_scoped_to_its_day_and_summary_is_computed_from_persisted_records(): void
    {
        [$event, $day, $registration] = $this->registration();
        $otherDay = EventDay::factory()->for($event)->create(['date' => '2027-01-17', 'day_number' => 2]);
        $otherRegistration = EventRegistration::factory()->for($event)->create();
        Attendance::factory()->create(['event_registration_id' => $otherRegistration->id, 'event_day_id' => $otherDay->id]);
        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))->assertCreated();

        $this->getJson(route('events.days.attendance', [$event, $day]))->assertOk()
            ->assertJsonCount(1, 'attendees')->assertJsonPath('attendees.0.participant_name', $registration->participant_name)
            ->assertJsonPath('summary.active_registrations', 2)->assertJsonPath('summary.checked_in', 1)
            ->assertJsonPath('summary.not_checked_in', 1)->assertJsonPath('summary.percentage', 50);
        $this->getJson(route('events.days.attendance', [$event, $otherDay]))->assertOk()
            ->assertJsonCount(1, 'attendees')->assertJsonPath('attendees.0.participant_name', $otherRegistration->participant_name);
    }

    public function test_failed_persistence_rolls_back_and_does_not_return_false_success(): void
    {
        [$event, $day, $registration] = $this->registration();
        $dispatcher = Attendance::getEventDispatcher();
        Attendance::setEventDispatcher(clone $dispatcher);
        Attendance::created(function (): void {
            throw new RuntimeException('Storage failed after insert.');
        });

        try {
            $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))
                ->assertStatus(500)->assertJsonPath('success', false)->assertJsonMissingPath('data')->assertJsonMissingPath('trace');
        } finally {
            Attendance::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame('registered', $registration->fresh()->status);
    }

    #[TestWith(['inactive'])]
    #[TestWith(['outside_range'])]
    public function test_confirmation_rechecks_day_eligibility_after_qr_validation(string $reason): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $day, $registration))->assertOk();
        $day->update($reason === 'inactive' ? ['is_active' => false] : ['date' => '2027-01-20']);

        $this->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonPath('success', false)->assertJsonValidationErrors('event_day_id');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_registration_cancelled_between_validation_and_confirmation_cannot_check_in(): void
    {
        [$event, $day, $registration] = $this->registration();
        $payload = $this->payload($event, $day, $registration);
        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $payload)->assertOk();
        $registration->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $original = $registration->fresh()->getAttributes();

        $this->postJson(route('attendance.check-in'), $payload)->assertUnprocessable()->assertJsonPath('success', false);
        $this->assertSame($original, $registration->fresh()->getAttributes());
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_cancelled_registration_history_remains_visible_without_inflating_active_summary(): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))->assertCreated();
        $history = Attendance::query()->sole()->getAttributes();
        $registration->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->postJson(route('attendance.check-in'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonPath('success', false);

        $this->getJson(route('events.days.attendance', [$event, $day]))->assertOk()->assertJsonCount(1, 'attendees')
            ->assertJsonPath('attendees.0.registration_status', 'cancelled')
            ->assertJsonPath('summary.active_registrations', 0)->assertJsonPath('summary.checked_in', 0)
            ->assertJsonPath('summary.not_checked_in', 0)->assertJsonPath('summary.percentage', 0);
        $this->get(route('events.days.show', [$event, $day]))->assertOk()->assertSee($registration->registration_number)
            ->assertSee($registration->participant_name)->assertSee('09:30:00');
        $this->assertSame($history, Attendance::query()->sole()->getAttributes());
    }

    public function test_monitoring_endpoint_requires_both_view_permissions_and_scoped_day(): void
    {
        [$event, $day] = $this->registration();
        [$otherEvent] = $this->registration();
        $this->getJson(route('events.days.attendance', [$event, $day]))->assertUnauthorized();
        $scannerOnly = User::factory()->create(['is_active' => true]);
        $scannerOnly->givePermissionTo('attendance.scan');
        $this->actingAs($scannerOnly)->getJson(route('events.days.attendance', [$event, $day]))->assertForbidden();
        $viewer = $this->staff(false);
        $this->actingAs($viewer)->getJson(route('events.days.attendance', [$event, $day]))->assertOk();
        $this->getJson(route('events.days.attendance', [$otherEvent, $day]))->assertNotFound();
        $this->postJson(route('attendance.check-in'), [])->assertForbidden();
    }

    public function test_unique_constraint_prevents_duplicate_rows_even_outside_the_service(): void
    {
        [$event, $day, $registration] = $this->registration();
        $attendance = Attendance::factory()->create(['event_registration_id' => $registration->id, 'event_day_id' => $day->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        Attendance::create($attendance->only(['event_registration_id', 'event_day_id', 'checked_in_by', 'checked_in_at']));
    }

    public function test_confirmation_locks_fresh_context_and_registration_in_one_transaction(): void
    {
        [$event, $day, $registration] = $this->registration();
        $scopes = Event::getAllGlobalScopes();
        $transactionLevel = DB::transactionLevel();
        $lockedModels = [];
        foreach ([Event::class, EventDay::class, EventRegistration::class, Attendance::class] as $model) {
            $model::addGlobalScope('observe_checkin_lock', function (Builder $query) use ($model, $transactionLevel, &$lockedModels): void {
                if ($query->getQuery()->lock === true) {
                    $this->assertGreaterThan($transactionLevel, DB::transactionLevel());
                    $lockedModels[] = $model;
                }
            });
        }

        try {
            $payload = $this->payload($event, $day, $registration);
            $this->actingAs($this->staff())->postJson(route('attendance.check-in'), $payload)->assertCreated();
            $this->postJson(route('attendance.check-in'), $payload)->assertOk()->assertJsonPath('created', false);
        } finally {
            Event::setAllGlobalScopes($scopes);
        }
        $this->assertSame([
            Event::class, EventDay::class, EventRegistration::class, Attendance::class,
            Event::class, EventDay::class, EventRegistration::class, Attendance::class,
        ], $lockedModels);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_event_day_with_attendance_history_cannot_be_deleted(): void
    {
        [$event, $day, $registration] = $this->registration();
        Attendance::factory()->create(['event_registration_id' => $registration->id, 'event_day_id' => $day->id]);

        $this->actingAs($this->staff())->delete(route('events.days.destroy', [$event, $day]))
            ->assertRedirect()->assertSessionHas('error', 'Hari pelaksanaan tidak dapat dihapus karena memiliki riwayat kehadiran.');
        $this->assertDatabaseCount('attendances', 1);
        $this->assertNotNull($day->fresh());
    }

    private function staff(bool $canScan = true): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($canScan ? ['attendance.scan', 'attendance.view', 'events.view', 'events.update'] : ['attendance.view', 'events.view']);

        return $user;
    }

    /** @return array{Event, EventDay, EventRegistration} */
    private function registration(string $type = 'employee'): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        $day = EventDay::factory()->for($event)->create(['date' => '2027-01-16']);
        $registration = EventRegistration::factory()->for($event)->when($type === 'external', fn ($factory) => $factory->external())->create();

        return [$event, $day, $registration];
    }

    /** @return array{event_id: int, event_day_id: int, qr_code: string} */
    private function payload(Event $event, EventDay $day, EventRegistration $registration): array
    {
        return ['event_id' => $event->id, 'event_day_id' => $day->id, 'qr_code' => 'RA1:'.$registration->uuid];
    }
}
