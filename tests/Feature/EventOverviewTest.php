<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventSchedule;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['events.view', 'events.update', 'attendance.scan'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Queue::fake();
    }

    #[TestWith(['draft', 'bg-secondary-lt'])]
    #[TestWith(['published', 'bg-success-lt'])]
    #[TestWith(['closed', 'bg-azure-lt'])]
    #[TestWith(['cancelled', 'bg-danger-lt'])]
    public function test_status_badge_follows_the_location_in_the_header_with_existing_colors(string $status, string $color): void
    {
        $event = Event::factory()->create([
            'title' => 'Raker 2026', 'location' => 'Hotel Cokro', 'status' => $status,
            'start_date' => '2026-11-16', 'end_date' => '2026-12-03',
        ]);

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk()
            ->assertSee('16 Nov 2026')->assertSee('03 Dec 2026')->assertDontSee('Status Event');

        $xpath = $this->xpath($response->getContent());
        $badge = $xpath->query('//div[contains(@class, "page-header")]//span[contains(@class, "badge")]');
        $this->assertSame(1, $badge->length);
        $this->assertSame(ucfirst($status), trim($badge->item(0)->textContent));
        $this->assertStringContainsString($color, $badge->item(0)->getAttribute('class'));
        $this->assertSame('Hotel Cokro', trim($xpath->evaluate('string(preceding-sibling::span[2])', $badge->item(0))));
    }

    public function test_header_keeps_the_status_visible_when_location_is_empty_and_escapes_event_text(): void
    {
        [$event] = $this->eventDays();
        $event->update(['location' => null, 'title' => '<script>alert("event")</script>']);

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk()
            ->assertSee($event->title)->assertDontSee($event->title, false);

        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//div[contains(@class, "page-header")]//span[contains(@class, "badge") and text()="Published"]')->length);
        $this->assertSame(0, $xpath->query('//div[contains(@class, "page-header")]//i[contains(@class, "ti-map-pin")]')->length);
    }

    public function test_overview_renders_exactly_four_ordered_cards_and_keeps_existing_navigation_and_actions(): void
    {
        [$event, $days] = $this->eventDays();
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        EventSchedule::factory()->for($days[0], 'eventDay')->count(2)->create();
        Attendance::factory()->create(['event_day_id' => $days[0]->id, 'event_registration_id' => $registrations[0]->id]);

        $response = $this->actingAs($this->viewer(['events.update', 'attendance.scan']))->get(route('events.show', $event))->assertOk()
            ->assertDontSee('Status Event')->assertSee('1 dari 4 potensi kehadiran')
            ->assertSee('href="'.route('events.index').'"', false)
            ->assertSee('href="'.route('events.edit', $event).'"', false)
            ->assertSee('href="'.route('events.scanner', $event).'"', false)
            ->assertSee('href="'.route('events.days.show', [$event, $days[0]]).'"', false)
            ->assertSee('href="#event-overview"', false)->assertSee('href="#event-days"', false)
            ->assertSee('href="#event-participants"', false)->assertSee($registrations[0]->registration_number);

        $xpath = $this->xpath($response->getContent());
        $labels = $xpath->query('//*[@aria-label="Ringkasan event"]//div[@class="subheader"]');
        $values = $xpath->query('//*[@aria-label="Ringkasan event"]//div[contains(@class, "h1")]');
        $this->assertSame(['Durasi Event', 'Event Days', 'Total Rundown', 'PERSENTASE KEHADIRAN EVENT'],
            array_map(fn ($node): string => trim($node->textContent), iterator_to_array($labels)));
        $this->assertSame(['3 Hari', '2', '2', '25%'],
            array_map(fn ($node): string => preg_replace('/\s+/', ' ', trim($node->textContent)), iterator_to_array($values)));
    }

    #[TestWith([0, 2])]
    #[TestWith([2, 0])]
    #[TestWith([0, 0])]
    public function test_zero_registrations_or_days_displays_zero_percent(int $registrationCount, int $dayCount): void
    {
        [$event] = $this->eventDays($dayCount);
        EventRegistration::factory()->for($event)->count($registrationCount)->create();

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk()
            ->assertSee('0 dari 0 potensi kehadiran');

        $this->assertSame(['checked_in' => 0, 'expected_attendance' => 0, 'percentage' => 0.0], $response->viewData('eventAttendanceSummary'));
        $this->assertSame('0%', $this->attendanceValue($response->getContent()));
    }

    #[TestWith([4, 2, 50.0, '50%'])]
    #[TestWith([3, 1, 33.33, '33.33%'])]
    #[TestWith([8, 5, 62.5, '62.5%'])]
    public function test_single_day_percentage_is_rounded_without_unnecessary_decimal_places(int $registrationCount, int $checkedInCount, float $percentage, string $display): void
    {
        [$event, $days] = $this->eventDays(1);
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count($registrationCount)->create();
        foreach ($registrations->take($checkedInCount) as $registration) {
            Attendance::factory()->create(['event_day_id' => $days[0]->id, 'event_registration_id' => $registration->id]);
        }

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk();

        $this->assertSame(['checked_in' => $checkedInCount, 'expected_attendance' => $registrationCount, 'percentage' => $percentage], $response->viewData('eventAttendanceSummary'));
        $this->assertSame($display, $this->attendanceValue($response->getContent()));
    }

    /**
     * @param  array<int, int>  $attendedDayIndexes
     */
    #[TestWith([2, [0], 1, 4, 25.0])]
    #[TestWith([3, [0, 1], 2, 6, 33.33])]
    public function test_multi_day_percentage_counts_each_registration_day_combination(int $dayCount, array $attendedDayIndexes, int $checkedIn, int $expectedAttendance, float $percentage): void
    {
        [$event, $days] = $this->eventDays($dayCount);
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        foreach ($attendedDayIndexes as $index) {
            Attendance::factory()->create(['event_day_id' => $days[$index]->id, 'event_registration_id' => $registrations[0]->id]);
        }

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk();

        $this->assertSame(['checked_in' => $checkedIn, 'expected_attendance' => $expectedAttendance, 'percentage' => $percentage], $response->viewData('eventAttendanceSummary'));
    }

    public function test_all_event_days_contribute_including_inactive_days_with_attendance_history(): void
    {
        [$event, $days] = $this->eventDays();
        $days[1]->update(['is_active' => false]);
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        Attendance::factory()->create(['event_day_id' => $days[1]->id, 'event_registration_id' => $registrations[0]->id]);

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk();

        $this->assertSame(['checked_in' => 1, 'expected_attendance' => 4, 'percentage' => 25.0], $response->viewData('eventAttendanceSummary'));
    }

    public function test_other_events_and_mismatched_registration_day_pairs_are_excluded(): void
    {
        [$event, $days] = $this->eventDays();
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        Attendance::factory()->create(['event_day_id' => $days[0]->id, 'event_registration_id' => $registrations[0]->id]);
        $otherAttendance = Attendance::factory()->create();
        Attendance::factory()->create(['event_day_id' => $days[1]->id, 'event_registration_id' => $otherAttendance->event_registration_id]);
        Attendance::factory()->create(['event_day_id' => $otherAttendance->event_day_id, 'event_registration_id' => $registrations[0]->id]);

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk();

        $this->assertSame(['checked_in' => 1, 'expected_attendance' => 4, 'percentage' => 25.0], $response->viewData('eventAttendanceSummary'));
    }

    public function test_cancelled_registrations_and_their_history_are_excluded_without_deleting_attendance(): void
    {
        [$event, $days] = $this->eventDays();
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        $cancelled = EventRegistration::factory()->for($event)->cancelled()->create();
        Attendance::factory()->create(['event_day_id' => $days[0]->id, 'event_registration_id' => $registrations[0]->id]);
        Attendance::factory()->create(['event_day_id' => $days[0]->id, 'event_registration_id' => $cancelled->id]);
        $this->actingAs($this->viewer());

        $response = $this->get(route('events.show', $event))->assertOk();
        $this->assertSame(['checked_in' => 1, 'expected_attendance' => 4, 'percentage' => 25.0], $response->viewData('eventAttendanceSummary'));
        $registrations[0]->update(['status' => EventRegistration::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $response = $this->get(route('events.show', $event))->assertOk();

        $this->assertSame(['checked_in' => 0, 'expected_attendance' => 2, 'percentage' => 0.0], $response->viewData('eventAttendanceSummary'));
        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_duplicate_checkin_confirmation_does_not_inflate_the_event_percentage(): void
    {
        [$event, $days] = $this->eventDays();
        $this->travelTo($days[0]->date->copy()->setTime(9, 30));
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        $payload = ['event_id' => $event->id, 'event_day_id' => $days[0]->id, 'qr_code' => 'RA1:'.$registrations[0]->uuid];
        $this->actingAs($this->viewer(['attendance.scan']));

        $this->postJson(route('attendance.check-in'), $payload)->assertCreated()->assertJsonPath('created', true);
        $this->postJson(route('attendance.check-in'), $payload)->assertOk()->assertJsonPath('created', false);
        $response = $this->get(route('events.show', $event))->assertOk();

        $this->assertSame(['checked_in' => 1, 'expected_attendance' => 4, 'percentage' => 25.0], $response->viewData('eventAttendanceSummary'));
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_fully_attended_event_displays_one_hundred_percent(): void
    {
        [$event, $days] = $this->eventDays();
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        foreach ($days as $day) {
            foreach ($registrations as $registration) {
                Attendance::factory()->create(['event_day_id' => $day->id, 'event_registration_id' => $registration->id]);
            }
        }

        $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk()
            ->assertSee('4 dari 4 potensi kehadiran');

        $this->assertSame(['checked_in' => 4, 'expected_attendance' => 4, 'percentage' => 100.0], $response->viewData('eventAttendanceSummary'));
        $this->assertSame('100%', $this->attendanceValue($response->getContent()));
    }

    public function test_repeated_query_rows_do_not_count_the_same_registration_day_pair_twice(): void
    {
        [$event, $days] = $this->eventDays();
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count(2)->create();
        Attendance::factory()->create(['event_day_id' => $days[0]->id, 'event_registration_id' => $registrations[0]->id]);
        $originalScopes = Attendance::getAllGlobalScopes();
        Attendance::addGlobalScope('repeated_attendance_rows', function (Builder $query) use ($event): void {
            $query->crossJoinSub($event->registrations()->select('id'), 'attendance_copies');
        });

        try {
            $response = $this->actingAs($this->viewer())->get(route('events.show', $event))->assertOk();

            $this->assertSame(['checked_in' => 1, 'expected_attendance' => 4, 'percentage' => 25.0], $response->viewData('eventAttendanceSummary'));
        } finally {
            Attendance::setAllGlobalScopes($originalScopes);
        }

        $this->assertDatabaseCount('attendances', 1);
    }

    #[TestWith(['guest'])]
    #[TestWith(['missing_permission'])]
    #[TestWith(['inactive'])]
    public function test_event_overview_does_not_expose_attendance_to_unauthorized_visitors(string $visitor): void
    {
        [$event] = $this->eventDays();
        if ($visitor === 'missing_permission') {
            $this->actingAs(User::factory()->create(['is_active' => true]));
        } elseif ($visitor === 'inactive') {
            $user = $this->viewer();
            $user->is_active = false;
            $user->save();
            $this->actingAs($user);
        }

        $response = $this->get(route('events.show', $event))->assertDontSee('PERSENTASE KEHADIRAN EVENT');

        if ($visitor === 'missing_permission') {
            $response->assertForbidden();
        } else {
            $response->assertRedirect(route('login'));
        }
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
     * @return array{Event, Collection<int, EventDay>}
     */
    private function eventDays(int $dayCount = 2): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        $days = EventDay::factory()->for($event)->count($dayCount)
            ->sequence(fn (Sequence $sequence): array => ['day_number' => $sequence->index + 1, 'date' => '2027-01-'.(15 + $sequence->index)])
            ->create();

        return [$event, $days];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    private function attendanceValue(string $html): string
    {
        return trim($this->xpath($html)->evaluate('string(//*[@aria-label="Ringkasan event"]/div[last()]//div[contains(@class, "h1")])'));
    }
}
