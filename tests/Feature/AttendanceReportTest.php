<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceReportService;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
    }

    #[TestWith(['super-admin'])]
    #[TestWith(['event-admin'])]
    #[TestWith(['monitoring'])]
    public function test_reporting_roles_can_open_the_tab_and_its_scoped_data(string $role): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user($role));

        $this->get(route('events.show', $fixture['event']))->assertOk()
            ->assertSee('href="#event-reports"', false)->assertSee('data-attendance-report-table', false)
            ->assertSee('href="#event-overview"', false)->assertSee('href="#event-days"', false)
            ->assertSee('href="#event-participants"', false);
        $this->getJson($this->url($fixture['event']))->assertOk()->assertJsonPath('recordsTotal', 9);
    }

    public function test_non_reporting_users_cannot_see_the_tab_or_access_report_data(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user('panitia'));

        $this->get(route('events.show', $fixture['event']))->assertOk()
            ->assertDontSee('href="#event-reports"', false)->assertDontSee('data-attendance-report-table', false);
        $this->get($this->url($fixture['event']))->assertForbidden()->assertHeader('Content-Type', 'application/json')->assertJsonMissingPath('data');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('reports.view');
        $this->actingAs($user)->getJson($this->url($fixture['event']))->assertForbidden();
    }

    public function test_guest_and_inactive_users_cannot_access_reports(): void
    {
        $event = Event::factory()->create();
        $this->get($this->url($event))->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $user = $this->user();
        $user->is_active = false;
        $user->save();

        $this->actingAs($user)->get($this->url($event))->assertForbidden()->assertHeader('Content-Type', 'application/json');
        $this->assertGuest();
    }

    public function test_report_urls_use_the_browser_origin_when_the_proxy_forwards_over_http(): void
    {
        $fixture = $this->fixture();
        $event = $fixture['event'];
        $path = route('events.reports.data', $event, absolute: false);
        config(['trustedproxy.proxies' => null]);

        $page = $this->actingAs($this->user())->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://rams.example.test'.route('events.show', $event, absolute: false))->assertOk();

        $this->assertSame(1, preg_match('/data-attendance-reports data-url="([^"]+)"/', $page->getContent(), $endpoint));
        $this->assertSame($path, $endpoint[1]);
        $this->get('https://rams.example.test'.$endpoint[1].'?attendance_status=present')->assertOk()
            ->assertHeader('Content-Type', 'application/json')->assertJsonPath('summary.expected', 4);
    }

    public function test_report_backend_errors_return_json_without_exposing_internal_details(): void
    {
        config(['app.debug' => false]);
        Exceptions::fake();
        $event = Event::factory()->create();
        $this->mock(AttendanceReportService::class)->shouldReceive('data')->once()
            ->andThrow(new RuntimeException('Internal report failure detail'));

        $this->actingAs($this->user())->get($this->url($event))->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/json')->assertDontSee('Internal report failure detail');
    }

    public function test_multi_day_report_has_one_row_per_active_registration_day_and_correct_summary(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event']))->assertOk()
            ->assertJsonPath('recordsTotal', 9)->assertJsonPath('recordsFiltered', 9)->assertJsonCount(9, 'data')
            ->assertJsonPath('summary.expected', 9)->assertJsonPath('summary.present', 4)
            ->assertJsonPath('summary.not_present', 5)->assertJsonPath('summary.percentage', 44.44)
            ->assertDontSee('Cancelled Participant')->assertDontSee('Other Event Participant');

        $pairs = array_map(fn (array $row): string => $row['registration_number'].'|'.$row['event_day_date'], $response->json('data'));
        $this->assertSame(9, count(array_unique($pairs)));
        $this->assertSame(['row_number', 'registration_number', 'participant_name', 'participant_type', 'department_name',
            'event_day', 'event_day_date', 'attendance_status', 'checkin_time', 'operator_name'], array_keys($response->json('data.0')));
        $present = collect($response->json('data'))->firstWhere('participant_name', 'Alice Employee');
        $this->assertSame('08:15:00', $present['checkin_time']);
        $this->assertSame('Check-in Operator', $present['operator_name']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_day_filter_has_consistent_totals_with_event_day_monitoring(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());

        $this->getJson($this->url($fixture['event'], ['event_day' => $fixture['days'][0]->id]))->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('summary.expected', 3)->assertJsonPath('summary.present', 2)
            ->assertJsonPath('summary.not_present', 1)->assertJsonPath('summary.percentage', 66.67);
        $this->getJson(route('events.days.attendance', [$fixture['event'], $fixture['days'][0]]))->assertOk()
            ->assertJsonPath('summary.active_registrations', 3)->assertJsonPath('summary.checked_in', 2)->assertJsonPath('summary.percentage', 66.67);
    }

    #[TestWith(['employee', 6, 3, 50])]
    #[TestWith(['external', 3, 1, 33.33])]
    public function test_participant_type_filter_scopes_rows_and_totals(string $type, int $expected, int $present, int|float $percentage): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], ['participant_type' => $type]))->assertOk()
            ->assertJsonCount($expected, 'data')->assertJsonPath('summary.expected', $expected)
            ->assertJsonPath('summary.present', $present)->assertJsonPath('summary.percentage', $percentage);

        $this->assertSame([$type], array_values(array_unique(array_column($response->json('data'), 'participant_type'))));
    }

    public function test_department_filter_only_includes_employees_in_that_department(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], ['department_id' => $fixture['department']->id]))->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('summary.expected', 3)->assertJsonPath('summary.present', 2)
            ->assertJsonPath('summary.percentage', 66.67);

        $this->assertSame(['Alice Employee'], array_values(array_unique(array_column($response->json('data'), 'participant_name'))));
        $this->getJson($this->url($fixture['event'], ['participant_type' => 'external', 'department_id' => $fixture['department']->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    #[TestWith(['present', 4, 4, 0, 100])]
    #[TestWith(['not_present', 5, 0, 5, 0])]
    public function test_attendance_filter_is_applied_to_the_same_summary_dataset(string $status, int $expected, int $present, int $absent, int $percentage): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], ['attendance_status' => $status]))->assertOk()
            ->assertJsonCount($expected, 'data')->assertJsonPath('summary.expected', $expected)->assertJsonPath('summary.present', $present)
            ->assertJsonPath('summary.not_present', $absent)->assertJsonPath('summary.percentage', $percentage);

        $this->assertSame([$status], array_values(array_unique(array_column($response->json('data'), 'attendance_status'))));
        if ($status === 'not_present') {
            $this->assertSame(['-'], array_values(array_unique(array_column($response->json('data'), 'checkin_time'))));
            $this->assertSame(['-'], array_values(array_unique(array_column($response->json('data'), 'operator_name'))));
        }
    }

    public function test_search_matches_participant_name_and_registration_number_and_updates_totals(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());

        $this->getJson($this->url($fixture['event'], ['search' => ['value' => 'Alice']]))->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('summary.expected', 3)->assertJsonPath('summary.present', 2);
        $response = $this->getJson($this->url($fixture['event'], ['search' => ['value' => $fixture['secondRegistration']->registration_number]]))->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('summary.expected', 3)->assertJsonPath('summary.present', 1);
        $this->assertSame(['Budi Employee'], array_values(array_unique(array_column($response->json('data'), 'participant_name'))));
    }

    public function test_filters_combine_before_summary_and_pagination(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($this->user())->getJson($this->url($fixture['event'], [
            'event_day' => $fixture['days'][1]->id, 'participant_type' => 'employee',
            'department_id' => $fixture['secondDepartment']->id, 'attendance_status' => 'present', 'search' => ['value' => 'Budi'],
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.expected', 1)
            ->assertJsonPath('summary.present', 1)->assertJsonPath('summary.percentage', 100);
    }

    public function test_modified_event_parameters_and_cross_event_days_are_rejected(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());

        $this->getJson($this->url($fixture['event'], ['event_day' => $fixture['otherDay']->id]))->assertUnprocessable()->assertJsonValidationErrors('event_day');
        $this->getJson($this->url($fixture['event'], ['event_id' => $fixture['otherDay']->event_id]))->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->getJson($this->url($fixture['event'], ['event' => $fixture['otherDay']->event->uuid]))->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->getJson(route('events.reports.data', (string) Str::uuid7()))->assertNotFound();
        $this->getJson(route('events.reports.data', $fixture['event']->id))->assertNotFound();
    }

    public function test_pagination_counts_and_row_numbers_cover_all_pages_without_duplicates(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());
        $pairs = [];
        foreach ([0, 4, 8] as $start) {
            $response = $this->getJson($this->url($fixture['event'], ['draw' => 7, 'start' => $start, 'length' => 4]))->assertOk()
                ->assertJsonPath('draw', 7)->assertJsonPath('recordsFiltered', 9)->assertJsonPath('summary.expected', 9)
                ->assertJsonPath('summary.present', 4)->assertJsonPath('data.0.row_number', $start + 1);
            foreach ($response->json('data') as $row) {
                $pairs[] = $row['registration_number'].'|'.$row['event_day_date'];
            }
        }

        $this->assertSame(9, count($pairs));
        $this->assertSame(9, count(array_unique($pairs)));
    }

    public function test_sorting_uses_allowlisted_columns_and_stable_ties(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], ['order' => [['column' => 2, 'dir' => 'desc']], 'length' => 2]))->assertOk()
            ->assertJsonPath('data.0.participant_name', 'Carla External')->assertJsonPath('data.1.participant_name', 'Carla External');
        $this->assertNotSame($response->json('data.0.event_day_date'), $response->json('data.1.event_day_date'));
    }

    #[TestWith(['event_day', 'bad'])]
    #[TestWith(['participant_type', 'cancelled'])]
    #[TestWith(['attendance_status', 'unknown'])]
    #[TestWith(['department_id', 999999])]
    #[TestWith(['length', -1])]
    #[TestWith(['length', 101])]
    #[TestWith(['start', -1])]
    #[TestWith(['draw', '<script>bad</script>'])]
    #[TestWith(['order', [['column' => 'name desc; drop table users', 'dir' => 'asc']]])]
    #[TestWith(['order', [['column' => 2, 'dir' => 'invalid']]])]
    #[TestWith(['search', ['value' => 'text', 'regex' => 'true']])]
    #[TestWith(['registration_status', 'cancelled'])]
    public function test_invalid_filters_and_datatable_parameters_return_422(string $key, mixed $value): void
    {
        $event = Event::factory()->create();
        $this->actingAs($this->user())->getJson($this->url($event, [$key => $value]))->assertUnprocessable();
    }

    public function test_search_treats_wildcards_literally_and_does_not_execute_sql(): void
    {
        $fixture = $this->fixture();
        $employee = Employee::factory()->create(['name' => '100%_! Person']);
        EventRegistration::factory()->for($fixture['event'])->invitedTo(...$fixture['days'])->for($employee)->create();
        $this->actingAs($this->user());

        $this->getJson($this->url($fixture['event'], ['search' => ['value' => '%_!']]))->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.participant_name', '100%_! Person');
        $this->getJson($this->url($fixture['event'], ['search' => ['value' => "' OR 1=1 --"]]))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('summary.expected', 0);
    }

    public function test_duplicate_attendance_query_rows_do_not_duplicate_report_slots(): void
    {
        $fixture = $this->fixture();
        $scopes = Attendance::getAllGlobalScopes();
        Attendance::addGlobalScope('repeat_attendance', function (Builder $query) use ($fixture): void {
            $query->crossJoinSub($fixture['event']->registrations()->select('id'), 'copies');
        });

        try {
            $this->actingAs($this->user())->getJson($this->url($fixture['event']))->assertOk()
                ->assertJsonCount(9, 'data')->assertJsonPath('summary.expected', 9)->assertJsonPath('summary.present', 4);
        } finally {
            Attendance::setAllGlobalScopes($scopes);
        }
    }

    public function test_cancellation_removes_current_slots_without_changing_attendance_history(): void
    {
        $fixture = $this->fixture();
        $fixture['secondRegistration']->update(['status' => EventRegistration::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->actingAs($this->user())->getJson($this->url($fixture['event']))->assertOk()
            ->assertJsonCount(6, 'data')->assertJsonPath('summary.expected', 6)->assertJsonPath('summary.present', 3);
        $this->assertDatabaseCount('attendances', 6);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_zero_registrations_or_zero_days_return_an_empty_report(bool $hasDay): void
    {
        $event = Event::factory()->create();
        if ($hasDay) {
            EventDay::factory()->for($event)->create();
        } else {
            EventRegistration::factory()->for($event)->create();
        }

        $this->actingAs($this->user())->getJson($this->url($event))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('recordsTotal', 0)->assertJsonPath('recordsFiltered', 0)
            ->assertJsonPath('summary.expected', 0)->assertJsonPath('summary.present', 0)
            ->assertJsonPath('summary.not_present', 0)->assertJsonPath('summary.percentage', 0);
    }

    public function test_query_count_is_constant_and_only_the_requested_page_is_loaded(): void
    {
        $fixture = $this->fixture();
        $service = app(AttendanceReportService::class);
        DB::enableQueryLog();

        try {
            $initial = $service->data($fixture['event'], ['length' => 2]);
            $queryCount = count(DB::getQueryLog());
            EventRegistration::factory()->for($fixture['event'])->invitedTo(...$fixture['days'])->count(20)->create();
            DB::flushQueryLog();
            $result = $service->data($fixture['event'], ['length' => 2]);
            $queries = DB::getQueryLog();

            $this->assertSame($queryCount, count($queries));
            $this->assertCount(2, $initial['data']);
            $this->assertCount(2, $result['data']);
            $this->assertSame(69, $result['recordsFiltered']);
            $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']) === 1)));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_report_markup_is_empty_until_requested_and_keeps_existing_exports_elsewhere(): void
    {
        $fixture = $this->fixture();
        $response = $this->actingAs($this->user())->get(route('events.show', $fixture['event']))->assertOk()
            ->assertSee('data-export="true"', false)->assertSee('href="'.route('analytic.index').'"', false);
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(0, $xpath->query('//*[@data-attendance-report-table]/tbody/tr')->length);
        $this->assertSame(1, $xpath->query('//*[@data-attendance-report-table and @data-export="false"]')->length);
        $this->assertSame(0, $xpath->query('//aside//a[@href="'.$this->url($fixture['event']).'"]')->length);
    }

    public function test_browser_fixtures_preserve_multi_day_scope_and_existing_scanner_entry(): void
    {
        $fixture = $this->fixture();
        EventRegistration::factory()->for($fixture['event'])->invitedTo(...$fixture['days'])->count(5)
            ->state(['employee_id' => Employee::factory()->state(['name' => 'Additional Employee'])])->create();
        $this->actingAs($this->user());
        $response = $this->get(route('events.show', $fixture['event']))->assertOk()->assertSee('href="'.route('events.scanner', $fixture['event']).'"', false);
        $this->get(route('events.scanner', $fixture['event']))->assertOk()->assertSee('data-attendance-scanner', false);
        $directory = getenv('REPORT_BROWSER_ARTIFACT_DIR');
        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.'/event.html', $response->getContent());
            file_put_contents($directory.'/print.html', $this->get(route('events.reports.print', $fixture['event']))->assertOk()->streamedContent());
            file_put_contents($directory.'/report.pdf', $this->get(route('events.reports.export.pdf', $fixture['event']))->assertOk()->getContent());
            $rows = app(AttendanceReportService::class)->data($fixture['event'], ['length' => 100]);
            file_put_contents($directory.'/fixture.json', json_encode([
                'rows' => $rows['data'], 'origin' => url('/'), 'url' => $this->url($fixture['event']),
                'day' => $fixture['days'][0]->id, 'day_date' => '2027-01-15', 'department' => $fixture['department']->id,
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function user(string $role = 'super-admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $parameters */
    private function url(Event $event, array $parameters = []): string
    {
        return route('events.reports.data', $event).($parameters === [] ? '' : '?'.http_build_query($parameters));
    }

    /**
     * @return array{event: Event, days: array<int, EventDay>, department: Department, secondDepartment: Department, secondRegistration: EventRegistration, otherDay: EventDay}
     */
    private function fixture(): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        $days = [];
        for ($day = 1; $day <= 3; $day++) {
            $days[] = EventDay::factory()->for($event)->create(['day_number' => $day, 'date' => '2027-01-'.(14 + $day)]);
        }
        $department = Department::factory()->create(['name' => 'Department Alpha']);
        $secondDepartment = Department::factory()->create(['name' => 'Department Beta']);
        $firstEmployee = Employee::factory()->for($department)->create(['name' => 'Alice Employee']);
        $secondEmployee = Employee::factory()->for($secondDepartment)->create(['name' => 'Budi Employee']);
        $firstRegistration = EventRegistration::factory()->for($event)->invitedTo(...$days)->for($firstEmployee)->create();
        $secondRegistration = EventRegistration::factory()->for($event)->invitedTo(...$days)->for($secondEmployee)->create();
        $external = EventRegistration::factory()->external()->for($event)->invitedTo(...$days)->create();
        $external->externalParticipant->update(['name' => 'Carla External']);
        $cancelled = EventRegistration::factory()->for($event)->invitedTo(...$days)->cancelled()->create();
        $cancelled->employee->update(['name' => 'Cancelled Participant']);
        $operator = User::factory()->create(['name' => 'Check-in Operator']);
        foreach ([[$firstRegistration, $days[0]], [$firstRegistration, $days[1]], [$secondRegistration, $days[1]], [$external, $days[0]], [$cancelled, $days[0]]] as [$registration, $day]) {
            Attendance::factory()->for($operator, 'operator')->create(['event_registration_id' => $registration->id, 'event_day_id' => $day->id, 'checked_in_at' => $day->date->copy()->setTime(8, 15)]);
        }
        $otherRegistration = EventRegistration::factory()->create();
        $otherRegistration->employee->update(['name' => 'Other Event Participant']);
        $otherDay = Attendance::factory()->create(['event_registration_id' => $otherRegistration->id])->eventDay;

        return compact('event', 'days', 'department', 'secondDepartment', 'secondRegistration', 'otherDay');
    }
}
