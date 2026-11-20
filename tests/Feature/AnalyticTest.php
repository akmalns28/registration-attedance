<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\AnalyticService;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AnalyticTest extends TestCase
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
    public function test_existing_reporting_roles_can_open_the_page_and_data(string $role): void
    {
        $this->actingAs($this->user($role));

        $this->get(route('analytic.index'))->assertOk()->assertViewIs('analytic.index')
            ->assertSee('data-analytic-page', false)->assertSee('All Events');
        $this->getJson(route('analytic.data'))->assertOk()->assertJsonPath('success', true);
    }

    public function test_reports_permission_does_not_require_scanner_or_event_edit_permissions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('reports.view');

        $this->actingAs($user)->get(route('analytic.index'))->assertOk();
        $this->getJson(route('analytic.data'))->assertOk();
        $this->postJson(route('attendance.check-in'), [])->assertForbidden();
    }

    #[TestWith(['panitia'])]
    #[TestWith(['none'])]
    public function test_users_without_reports_permission_receive_403_on_both_endpoints(string $role): void
    {
        $this->actingAs($this->user($role));

        $this->get(route('analytic.index'))->assertForbidden()->assertDontSee('data-analytic-initial', false);
        $this->getJson(route('analytic.data'))->assertForbidden()->assertJsonMissingPath('data.kpis');
    }

    public function test_guest_and_inactive_users_cannot_access_analytics(): void
    {
        $this->get(route('analytic.index'))->assertRedirect(route('login'));
        $this->getJson(route('analytic.data'))->assertUnauthorized();
        $user = $this->user();
        $user->is_active = false;
        $user->save();

        $this->actingAs($user)->getJson(route('analytic.data'))->assertForbidden()->assertJsonMissingPath('data.kpis');
        $this->assertGuest();
    }

    public function test_sidebar_uses_the_exact_analytic_label_and_active_state_without_an_event_tab(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());

        $response = $this->get(route('analytic.index'))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//aside//a[@href="'.route('analytic.index').'" and @aria-current="page"]');
        $this->assertSame(1, $links->length);
        $this->assertSame('Analytic', trim($links->item(0)->textContent));
        $this->assertSame(6, $xpath->query('//*[@data-analytic-kpi]')->length);
        $this->assertSame(4, $xpath->query('//*[@data-analytic-chart]')->length);
        $this->get(route('events.show', $fixture['first']))->assertOk()
            ->assertSee('href="#event-days"', false)->assertDontSee('href="#analytics"', false)
            ->assertDontSee('Attendance Monitoring');
        $this->actingAs($this->user('panitia'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('href="'.route('analytic.index').'"', false);
    }

    public function test_all_event_metrics_deduplicate_master_participants_and_distinguish_weighted_and_average_rates(): void
    {
        $this->fixture();

        $response = $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 2)->assertJsonPath('data.kpis.total_registrations', 8)
            ->assertJsonPath('data.kpis.participants_attended', 2)->assertJsonPath('data.kpis.attended_registrations', 3)
            ->assertJsonPath('data.kpis.participant_metric', 'participants')->assertJsonPath('data.kpis.total_checkins', 4)
            ->assertJsonPath('data.kpis.expected_attendance', 10)->assertJsonPath('data.kpis.attendance_rate', 40)
            ->assertJsonPath('data.kpis.average_event_rate', 45.83)->assertJsonPath('data.kpis.eligible_events', 2)
            ->assertJsonPath('data.charts.participant_types.0.count', 3)->assertJsonPath('data.charts.participant_types.1.count', 1)
            ->assertJsonCount(3, 'data.charts.by_day')->assertJsonCount(3, 'data.charts.attendance_trend');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([75, 16.67], array_column($response->json('data.charts.by_event'), 'percentage'));
    }

    public function test_event_filter_limits_metrics_and_day_options_to_the_selected_event(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($this->user())->getJson(route('analytic.data', ['event' => $fixture['first']->uuid]))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 1)->assertJsonPath('data.kpis.total_registrations', 2)
            ->assertJsonPath('data.kpis.participants_attended', 2)->assertJsonPath('data.kpis.total_checkins', 3)
            ->assertJsonPath('data.kpis.expected_attendance', 4)->assertJsonPath('data.kpis.attendance_rate', 75)
            ->assertJsonPath('data.kpis.average_event_rate', 75)->assertJsonCount(1, 'data.charts.by_event');

        $this->assertSame(array_column($fixture['firstDays'], 'id'), array_column($response->json('data.event_days'), 'id'));
    }

    public function test_date_range_filters_day_dates_inclusively_and_recalculates_expected_opportunities(): void
    {
        $this->fixture();

        $this->actingAs($this->user())->getJson(route('analytic.data', ['start_date' => '2027-01-16', 'end_date' => '2027-01-17']))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 2)->assertJsonPath('data.kpis.total_registrations', 8)
            ->assertJsonPath('data.kpis.total_checkins', 2)->assertJsonPath('data.kpis.expected_attendance', 8)
            ->assertJsonPath('data.kpis.attendance_rate', 25)->assertJsonPath('data.kpis.average_event_rate', 33.33)
            ->assertJsonPath('data.kpis.participants_attended', 1)->assertJsonCount(2, 'data.charts.by_day');
        $this->getJson(route('analytic.data', ['start_date' => '2027-01-15', 'end_date' => '2027-01-15']))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 1)->assertJsonPath('data.kpis.total_checkins', 2)
            ->assertJsonPath('data.kpis.expected_attendance', 2)->assertJsonPath('data.kpis.attendance_rate', 100);
    }

    #[TestWith(['start_date', '2027-01-17', 1])]
    #[TestWith(['end_date', '2027-01-15', 2])]
    public function test_open_ended_date_filters_are_supported(string $key, string $date, int $checkedIn): void
    {
        $this->fixture();

        $this->actingAs($this->user())->getJson(route('analytic.data', [$key => $date]))->assertOk()
            ->assertJsonPath('data.kpis.total_checkins', $checkedIn);
    }

    public function test_event_day_filter_has_the_same_meaning_as_existing_event_day_monitoring(): void
    {
        $fixture = $this->fixture();
        $parameters = ['event' => $fixture['first']->uuid, 'event_day' => $fixture['firstDays'][1]->id];
        $this->actingAs($this->user());

        $this->getJson(route('analytic.data', $parameters))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 1)->assertJsonPath('data.kpis.total_registrations', 2)
            ->assertJsonPath('data.kpis.total_checkins', 1)->assertJsonPath('data.kpis.expected_attendance', 2)
            ->assertJsonPath('data.kpis.attendance_rate', 50)->assertJsonPath('data.kpis.average_event_rate', 50)
            ->assertJsonCount(1, 'data.charts.by_day');
        $this->getJson(route('events.days.attendance', [$fixture['first'], $fixture['firstDays'][1]]))->assertOk()
            ->assertJsonPath('summary.checked_in', 1)->assertJsonPath('summary.active_registrations', 2)->assertJsonPath('summary.percentage', 50);
        $this->get(route('events.days.show', [$fixture['first'], $fixture['firstDays'][1]]))->assertOk()
            ->assertSee('data-attendance-scanner', false)->assertSee('data-attendance-table', false);
    }

    public function test_trend_combines_same_date_opportunities_while_day_labels_distinguish_events(): void
    {
        $fixture = $this->fixture();
        $fixture['second']->update(['start_date' => '2027-01-15', 'end_date' => '2027-01-15']);
        $fixture['secondDay']->update(['date' => '2027-01-15']);

        $response = $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonCount(2, 'data.charts.attendance_trend')
            ->assertJsonPath('data.charts.attendance_trend.0.date', '2027-01-15')
            ->assertJsonPath('data.charts.attendance_trend.0.checked_in', 3)
            ->assertJsonPath('data.charts.attendance_trend.0.expected', 8)
            ->assertJsonPath('data.charts.attendance_trend.0.percentage', 37.5);

        $labels = array_column($response->json('data.charts.by_day'), 'label');
        $this->assertStringContainsString('Alpha Event', $labels[0]);
        $this->assertStringContainsString('Beta Event', $labels[1]);
    }

    public function test_cross_event_day_ids_and_days_without_event_context_are_rejected(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());

        $this->getJson(route('analytic.data', ['event' => $fixture['second']->uuid, 'event_day' => $fixture['firstDays'][0]->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day')->assertJsonMissingPath('data.kpis');
        $this->getJson(route('analytic.data', ['event_day' => $fixture['firstDays'][0]->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day');
        $this->getJson(route('analytic.data', ['event' => $fixture['first']->uuid, 'event_day' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day');
    }

    #[TestWith(['event', 'invalid'])]
    #[TestWith(['event', ['invalid']])]
    #[TestWith(['start_date', '2027-02-30'])]
    #[TestWith(['end_date', 'tomorrow'])]
    #[TestWith(['event_day', '1 or 1=1'])]
    public function test_invalid_filters_return_422(string $key, mixed $value): void
    {
        $this->actingAs($this->user())->getJson(route('analytic.data', [$key => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($key)->assertJsonMissingPath('data.kpis');
    }

    public function test_unknown_events_and_reversed_date_ranges_are_rejected(): void
    {
        $this->actingAs($this->user())->getJson(route('analytic.data', ['event' => (string) Str::uuid7()]))
            ->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->getJson(route('analytic.data', ['start_date' => '2027-01-18', 'end_date' => '2027-01-15']))
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_cancellation_excludes_the_registration_and_its_history_from_all_current_metrics(): void
    {
        $fixture = $this->fixture();
        $fixture['externalRegistration']->update(['status' => EventRegistration::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonPath('data.kpis.total_registrations', 7)->assertJsonPath('data.kpis.participants_attended', 1)
            ->assertJsonPath('data.kpis.total_checkins', 3)->assertJsonPath('data.kpis.expected_attendance', 8)
            ->assertJsonPath('data.kpis.attendance_rate', 37.5)->assertJsonPath('data.kpis.average_event_rate', 58.33)
            ->assertJsonPath('data.charts.participant_types.1.count', 0);
        $this->assertDatabaseCount('attendances', 4);
    }

    public function test_mismatched_event_registration_day_pairs_are_never_counted(): void
    {
        $fixture = $this->fixture();
        Attendance::factory()->create(['event_registration_id' => $fixture['externalRegistration']->id, 'event_day_id' => $fixture['secondDay']->id]);

        $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonPath('data.kpis.total_checkins', 4)->assertJsonPath('data.kpis.expected_attendance', 10);
    }

    public function test_incomplete_master_identity_falls_back_to_registrations_attended(): void
    {
        $fixture = $this->fixture();
        $fixture['externalRegistration']->update(['external_participant_id' => null]);

        $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonPath('data.kpis.participant_metric', 'registrations')
            ->assertJsonPath('data.kpis.participants_attended', 3)->assertJsonPath('data.kpis.total_checkins', 4);
        $this->get(route('analytic.index'))->assertOk()->assertSee('Registrations Attended');
    }

    public function test_zero_opportunity_events_are_safe_and_do_not_bias_the_average(): void
    {
        $this->fixture();
        $noRegistrations = Event::factory()->create(['title' => 'No registrations']);
        EventDay::factory()->for($noRegistrations)->create();
        $noDays = Event::factory()->create(['title' => 'No days']);
        EventRegistration::factory()->for($noDays)->count(3)->create();

        $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 4)->assertJsonPath('data.kpis.total_registrations', 11)
            ->assertJsonPath('data.kpis.eligible_events', 2)->assertJsonPath('data.kpis.attendance_rate', 40)
            ->assertJsonPath('data.kpis.average_event_rate', 45.83)->assertJsonCount(4, 'data.charts.by_event');
        $this->getJson(route('analytic.data', ['event' => $noDays->uuid]))->assertOk()
            ->assertJsonPath('data.kpis.attendance_rate', 0)->assertJsonPath('data.kpis.average_event_rate', 0);
    }

    public function test_events_with_opportunities_but_no_attendance_contribute_a_zero_event_rate(): void
    {
        $this->fixture();
        $event = Event::factory()->create(['title' => 'Zero attendance']);
        $day = EventDay::factory()->for($event)->create();
        EventRegistration::factory()->for($event)->invitedTo($day)->count(2)->create();

        $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 3)->assertJsonPath('data.kpis.expected_attendance', 12)
            ->assertJsonPath('data.kpis.attendance_rate', 33.33)->assertJsonPath('data.kpis.average_event_rate', 30.56);
    }

    public function test_empty_database_and_empty_date_scope_return_valid_empty_states(): void
    {
        $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 0)->assertJsonPath('data.kpis.total_checkins', 0)
            ->assertJsonPath('data.kpis.attendance_rate', 0)->assertJsonPath('data.kpis.average_event_rate', 0)
            ->assertJsonCount(0, 'data.charts.by_event')->assertJsonCount(0, 'data.charts.by_day');
        $this->fixture();
        $this->getJson(route('analytic.data', ['start_date' => '2030-01-01']))->assertOk()
            ->assertJsonPath('data.kpis.total_events', 0)->assertJsonPath('data.kpis.total_registrations', 0)
            ->assertJsonCount(0, 'data.charts.attendance_trend');
    }

    public function test_repeated_attendance_query_rows_do_not_inflate_metrics(): void
    {
        $fixture = $this->fixture();
        $scopes = Attendance::getAllGlobalScopes();
        Attendance::addGlobalScope('repeat_rows', function (Builder $query) use ($fixture): void {
            $query->crossJoinSub($fixture['first']->registrations()->select('id'), 'attendance_copies');
        });

        try {
            $this->actingAs($this->user())->getJson(route('analytic.data'))->assertOk()
                ->assertJsonPath('data.kpis.total_checkins', 4)->assertJsonPath('data.kpis.attendance_rate', 40)
                ->assertJsonPath('data.kpis.participants_attended', 2);
        } finally {
            Attendance::setAllGlobalScopes($scopes);
        }
    }

    public function test_query_count_is_constant_and_analytic_reads_never_write_operational_data(): void
    {
        $this->fixture();
        $service = app(AnalyticService::class);
        DB::enableQueryLog();

        try {
            $service->data([]);
            $queryCount = count(DB::getQueryLog());
            $this->fixture();
            DB::flushQueryLog();
            $service->data([]);
            $queries = DB::getQueryLog();

            $this->assertSame($queryCount, count($queries));
            $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']) === 1)));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertDatabaseCount('attendances', 8);
        Queue::assertNothingPushed();
    }

    public function test_page_preserves_initial_filters_and_provides_safe_browser_fixtures(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());
        $response = $this->get(route('analytic.index', ['event' => $fixture['first']->uuid, 'event_day' => $fixture['firstDays'][0]->id]))
            ->assertOk()->assertSee('value="'.$fixture['first']->uuid.'" selected', false)
            ->assertSee('value="'.$fixture['firstDays'][0]->id.'" selected', false)->assertSee('100%');
        $directory = getenv('ANALYTIC_BROWSER_ARTIFACT_DIR');
        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.'/analytic.html', $this->get(route('analytic.index'))->assertOk()->getContent());
            file_put_contents($directory.'/fixture.json', json_encode([
                'all' => app(AnalyticService::class)->data([]),
                'first' => app(AnalyticService::class)->data(['event' => $fixture['first']->uuid]),
                'day' => $response->viewData('analytics'),
                'second' => app(AnalyticService::class)->data(['event' => $fixture['second']->uuid]),
                'empty' => app(AnalyticService::class)->data(['start_date' => '2030-01-01']),
                'origin' => url('/'),
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function user(string $role = 'super-admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        if ($role !== 'none') {
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * @return array{first: Event, second: Event, firstDays: array<int, EventDay>, secondDay: EventDay, externalRegistration: EventRegistration}
     */
    private function fixture(): array
    {
        $employee = Employee::factory()->create();
        $external = ExternalParticipant::factory()->create();
        $first = Event::factory()->published()->create(['title' => 'Alpha Event', 'start_date' => '2027-01-15', 'end_date' => '2027-01-16']);
        $second = Event::factory()->published()->create(['title' => 'Beta Event', 'start_date' => '2027-01-17', 'end_date' => '2027-01-17']);
        $firstDays = [
            EventDay::factory()->for($first)->create(['date' => '2027-01-15', 'day_number' => 1]),
            EventDay::factory()->for($first)->create(['date' => '2027-01-16', 'day_number' => 2]),
        ];
        $secondDay = EventDay::factory()->for($second)->create(['date' => '2027-01-17']);
        $firstEmployee = EventRegistration::factory()->for($first)->invitedTo(...$firstDays)->for($employee)->create();
        $secondEmployee = EventRegistration::factory()->for($second)->invitedTo($secondDay)->for($employee)->create();
        $externalRegistration = EventRegistration::factory()->external()->for($first)->invitedTo(...$firstDays)->for($external, 'externalParticipant')->create();
        EventRegistration::factory()->for($second)->invitedTo($secondDay)->count(5)->create();
        $operator = User::factory()->create();
        foreach ([$firstDays[0], $firstDays[1]] as $day) {
            Attendance::factory()->for($operator, 'operator')->create(['event_day_id' => $day->id, 'event_registration_id' => $firstEmployee->id]);
        }
        Attendance::factory()->for($operator, 'operator')->create(['event_day_id' => $firstDays[0]->id, 'event_registration_id' => $externalRegistration->id]);
        Attendance::factory()->for($operator, 'operator')->create(['event_day_id' => $secondDay->id, 'event_registration_id' => $secondEmployee->id]);

        return compact('first', 'second', 'firstDays', 'secondDay', 'externalRegistration');
    }
}
