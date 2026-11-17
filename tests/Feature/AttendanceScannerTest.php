<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventSchedule;
use App\Models\User;
use App\Services\AttendanceQrValidationService;
use App\Services\RegistrationQrCodeService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('attendance.scan', 'web');
        Queue::fake();
    }

    #[TestWith(['employee'])]
    #[TestWith(['external'])]
    public function test_valid_qr_resolves_only_the_expected_participant_fields(string $type): void
    {
        [$event, $day, $registration] = $this->registration($type);
        $payload = $this->payload($event, $day, $registration);
        $response = $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $payload)
            ->assertOk()->assertExactJson([
                'success' => true,
                'message' => 'Registrasi peserta valid. Kehadiran belum dicatat.',
                'data' => [
                    'registration_number' => $registration->registration_number,
                    'participant_name' => $registration->participant_name,
                    'participant_type' => $type,
                    'event_name' => $event->title,
                    'event_day' => $day->date->toDateString(),
                    'registration_status' => 'registered',
                ],
            ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSee($registration->uuid)->assertJsonMissingPath('data.nip')
            ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.phone');
        $this->assertDatabaseCount('attendances', 0);

        $directory = getenv('SCANNER_BROWSER_ARTIFACT_DIR');
        if ($type === 'employee' && is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.'/registration.png', app(RegistrationQrCodeService::class)->generatePng($registration));
            file_put_contents($directory.'/scanner.html', $this->get(route('attendance.scanner'))->assertOk()->getContent());
            file_put_contents($directory.'/event-scanner.html', $this->get(route('events.scanner', $event))->assertOk()->getContent());
            foreach (['events.view', 'events.update', 'attendance.view'] as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
            $this->actingAs($this->staff());
            auth()->user()->givePermissionTo(['events.view', 'attendance.view']);
            EventSchedule::factory()->for($day, 'eventDay')->create([
                'title' => 'Morning Briefing', 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'pic' => 'Morning PIC',
            ]);
            EventSchedule::factory()->for($day, 'eventDay')->create([
                'title' => 'Afternoon Workshop', 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'pic' => 'Afternoon PIC',
            ]);
            file_put_contents($directory.'/day-detail.html', $this->get(route('events.days.show', [$event, $day]))->assertOk()->getContent());
            auth()->user()->givePermissionTo('events.update');
            file_put_contents($directory.'/day-detail-admin.html', $this->get(route('events.days.show', [$event, $day]))->assertOk()->getContent());
            $regressionRegistration = EventRegistration::factory()->create(['uuid' => '01a1e3c3-85b2-7333-a1c8-b73ed6dab9a3']);
            file_put_contents($directory.'/registration-regression.png', app(RegistrationQrCodeService::class)->generatePng($regressionRegistration));
            $patternPayloads = [];
            foreach (['01a1e4db-b380-70a5-a11b-d5511fdb70ec', '01a1e4d8-c37d-700d-bb3b-ae0e22fbb789', ...array_map(
                fn (int $digit): string => '12345678-1234-7123-8123-123456789ab'.$digit, range(0, 7),
            )] as $index => $uuid) {
                $patternRegistration = EventRegistration::factory()->create(['uuid' => $uuid]);
                file_put_contents($directory.'/registration-pattern-'.$index.'.png', app(RegistrationQrCodeService::class)->generatePng($patternRegistration));
                $patternPayloads[] = 'RA1:'.$uuid;
            }
            $this->travelTo($day->date->copy()->setTime(9, 30));
            $checkin = $this->postJson(route('attendance.check-in'), $payload)->assertCreated()->json();
            $duplicate = $this->postJson(route('attendance.check-in'), $payload)->assertOk()->json();
            $attendance = $this->getJson(route('events.days.attendance', [$event, $day]))->assertOk()->json();
            file_put_contents($directory.'/fixture.json', json_encode([
                'payload' => $payload,
                'regression_payload' => 'RA1:'.$regressionRegistration->uuid,
                'pattern_payloads' => $patternPayloads,
                'response' => $response->json(),
                'checkin_response' => $checkin,
                'duplicate_response' => $duplicate,
                'attendance_response' => $attendance,
                'origin' => url('/'),
            ], JSON_THROW_ON_ERROR));
        }
    }

    #[TestWith([''])]
    #[TestWith(['  '])]
    #[TestWith(['not a QR payload'])]
    #[TestWith(['RA2:01a1cf4f-9c62-721a-9b5a-d478aea18229'])]
    #[TestWith(['RA1:not-a-uuid'])]
    #[TestWith(['RA1:------------------------------------'])]
    #[TestWith(['https://example.test/registrations/01a1cf4f-9c62-721a-9b5a-d478aea18229'])]
    #[TestWith([['unexpected' => 'array']])]
    #[TestWith([123])]
    public function test_invalid_qr_payloads_are_rejected_without_data_disclosure(mixed $qrCode): void
    {
        [$event, $day, $registration] = $this->registration();

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), [
            ...$this->payload($event, $day, $registration), 'qr_code' => $qrCode,
        ])->assertUnprocessable()->assertJsonPath('success', false)
            ->assertJsonValidationErrors('qr_code')->assertJsonMissingPath('data')
            ->assertJsonMissingPath('trace')->assertJsonMissingPath('exception');
    }

    public function test_unknown_registration_is_rejected(): void
    {
        [$event, $day, $registration] = $this->registration();

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), [
            ...$this->payload($event, $day, $registration), 'qr_code' => 'RA1:'.Str::uuid7(),
        ])->assertUnprocessable()->assertJsonPath('message', 'Registrasi tidak ditemukan.');
    }

    #[TestWith(['event_id'])]
    #[TestWith(['event_day_id'])]
    #[TestWith(['qr_code'])]
    public function test_required_scanner_fields_cannot_be_omitted(string $field): void
    {
        [$event, $day, $registration] = $this->registration();
        $payload = $this->payload($event, $day, $registration);
        unset($payload[$field]);

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    #[TestWith(['event_id', -1])]
    #[TestWith(['event_day_id', -1])]
    #[TestWith(['event_id', 999999])]
    #[TestWith(['event_day_id', 999999])]
    #[TestWith(['event_id', ['id' => 1]])]
    #[TestWith(['event_day_id', 'invalid'])]
    public function test_invalid_selection_ids_are_rejected(string $field, mixed $value): void
    {
        [$event, $day, $registration] = $this->registration();

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), [
            ...$this->payload($event, $day, $registration), $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_registration_from_another_event_is_rejected(): void
    {
        [$event, $day] = $this->registration();
        $otherRegistration = EventRegistration::factory()->create();

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $day, $otherRegistration))
            ->assertUnprocessable()->assertJsonValidationErrors('qr_code')->assertJsonMissingPath('data')
            ->assertDontSee($otherRegistration->participant_name);
    }

    public function test_event_day_from_another_event_is_rejected(): void
    {
        [$event, , $registration] = $this->registration();
        $otherDay = EventDay::factory()->create();

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $otherDay, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id')->assertJsonMissingPath('data');
    }

    #[TestWith(['draft'])]
    #[TestWith(['closed'])]
    #[TestWith(['cancelled'])]
    public function test_ineligible_event_status_is_rejected(string $status): void
    {
        [$event, $day, $registration] = $this->registration();
        $event->update(['status' => $status]);

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('event_id');
    }

    public function test_cancelled_registration_remains_cancelled_after_rejection(): void
    {
        [$event, $day, $registration] = $this->registration();
        $registration->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $attributes = $registration->fresh()->getAttributes();

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('qr_code');

        $this->assertSame($attributes, $registration->fresh()->getAttributes());
    }

    #[TestWith(['employee'])]
    #[TestWith(['external'])]
    public function test_inactive_participant_is_rejected(string $type): void
    {
        [$event, $day, $registration] = $this->registration($type);
        $participant = $type === 'employee' ? $registration->employee : $registration->externalParticipant;
        $participant->update(['is_active' => false]);

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('qr_code');
    }

    #[TestWith(['inactive'])]
    #[TestWith(['outside_range'])]
    public function test_ineligible_event_day_is_rejected(string $reason): void
    {
        [$event, $day, $registration] = $this->registration();
        $day->update($reason === 'inactive' ? ['is_active' => false] : ['date' => '2027-01-20']);

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $day, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
    }

    public function test_scanner_page_only_exposes_eligible_events_and_days(): void
    {
        [$event, $day] = $this->registration();
        $inactiveDay = EventDay::factory()->for($event)->inactive()->create(['day_number' => 2, 'date' => '2027-01-17', 'title' => 'Inactive day must stay hidden']);
        $outsideDay = EventDay::factory()->for($event)->create(['day_number' => 3, 'date' => '2027-01-20', 'title' => 'Outside event range']);
        $draft = Event::factory()->create(['title' => 'Draft event must stay hidden']);
        EventDay::factory()->for($draft)->create();

        $this->actingAs($this->staff())->get(route('attendance.scanner'))->assertOk()
            ->assertSee($event->title)->assertSee('data-attendance-scanner', false)
            ->assertSee('id="qr-reader"', false)->assertSee('Pindai Lagi')->assertSee('Kehadiran belum dicatat.')
            ->assertDontSee($draft->title)->assertDontSee($inactiveDay->title)->assertDontSee($outsideDay->title)
            ->assertDontSee('NIP')->assertDontSee('cdn.jsdelivr.net');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->get(route('attendance.scanner'))->assertRedirect(route('login'));
        $this->postJson(route('attendance.scanner.validate'), [])->assertUnauthorized()->assertJsonPath('success', false)->assertJsonMissingPath('trace');
    }

    public function test_staff_without_scan_permission_cannot_access_either_endpoint(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));
        $this->get(route('attendance.scanner'))->assertForbidden();
        $this->postJson(route('attendance.scanner.validate'), [])->assertForbidden()->assertJsonPath('success', false)->assertJsonMissingPath('trace');
    }

    public function test_inactive_staff_is_logged_out_and_rejected(): void
    {
        $staff = $this->staff();
        $staff->is_active = false;
        $staff->save();

        $this->actingAs($staff)->postJson(route('attendance.scanner.validate'), [])->assertForbidden()->assertJsonPath('success', false);
        $this->assertGuest();
        $this->actingAs($staff)->get(route('attendance.scanner'))->assertRedirect(route('login'));
    }

    public function test_scanning_is_read_only_even_when_repeated_or_browser_identity_is_spoofed(): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->actingAs($this->staff());
        $tables = ['events', 'event_days', 'event_registrations', 'employees', 'external_participants', 'event_registration_notifications', 'jobs'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson(route('attendance.scanner.validate'), [
                ...$this->payload($event, $day, $registration),
                'participant_name' => 'Forged participant', 'employee_id' => 99999, 'status' => 'cancelled',
            ])->assertOk()->assertJsonPath('data.participant_name', $registration->participant_name);
        }

        $this->assertSame([], $writes);
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
        Queue::assertNothingPushed();
    }

    public function test_validation_endpoint_is_rate_limited(): void
    {
        $this->actingAs($this->staff());
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson(route('attendance.scanner.validate'), [])->assertUnprocessable();
        }
        $this->postJson(route('attendance.scanner.validate'), [])->assertStatus(429)
            ->assertJsonPath('success', false)->assertHeader('Retry-After')->assertJsonMissingPath('trace');
    }

    public function test_csrf_middleware_rejects_scanner_post_without_a_token(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), [])->assertStatus(419)->assertJsonPath('success', false);
    }

    public function test_server_failures_do_not_disclose_stack_traces_or_raw_payloads(): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->mock(AttendanceQrValidationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validate')->once()->andThrow(new RuntimeException('Private server details.'));
        });

        $this->actingAs($this->staff())->postJson(route('attendance.scanner.validate'), $this->payload($event, $day, $registration))
            ->assertStatus(500)->assertExactJson(['success' => false, 'message' => 'Validasi tidak dapat diproses. Silakan coba lagi.'])
            ->assertDontSee($registration->uuid)->assertDontSee('Private server details.');
    }

    private function staff(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('attendance.scan');

        return $user;
    }

    /**
     * @return array{Event, EventDay, EventRegistration}
     */
    private function registration(string $type = 'employee'): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        $day = EventDay::factory()->for($event)->create(['date' => '2027-01-16']);
        $registration = EventRegistration::factory()->for($event)
            ->when($type === 'external', fn ($factory) => $factory->external())->create();

        return [$event, $day, $registration];
    }

    /**
     * @return array{event_id: int, event_day_id: int, qr_code: string}
     */
    private function payload(Event $event, EventDay $day, EventRegistration $registration): array
    {
        return ['event_id' => $event->id, 'event_day_id' => $day->id, 'qr_code' => 'RA1:'.$registration->uuid];
    }
}
