<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventAttendanceScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('attendance.scan', 'web');
        Permission::findOrCreate('events.view', 'web');
        Queue::fake();
    }

    #[TestWith(['published', true])]
    #[TestWith(['draft', true])]
    #[TestWith(['closed', true])]
    #[TestWith(['cancelled', true])]
    #[TestWith(['published', false])]
    public function test_event_header_only_offers_scan_qr_when_status_and_permission_allow_it(string $status, bool $canScan): void
    {
        $event = Event::factory()->create(['status' => $status]);
        $response = $this->actingAs($this->staff($canScan))->get(route('events.show', $event))->assertOk();
        $link = 'href="'.route('events.scanner', $event).'"';

        if ($status === Event::STATUS_PUBLISHED && $canScan) {
            $response->assertSee($link, false)->assertSee('Scan QR');
        } else {
            $response->assertDontSee($link, false)->assertDontSee('Scan QR');
        }
    }

    public function test_event_scanner_preselects_and_locks_the_uuid_bound_event_and_only_lists_its_days(): void
    {
        [$event, $day] = $this->registration();
        [$otherEvent, $otherDay] = $this->registration();
        EventDay::factory()->for($event)->inactive()->create(['date' => '2027-01-17', 'day_number' => 2]);

        $response = $this->actingAs($this->staff())->get(route('events.scanner', $event))->assertOk()
            ->assertSee('Scan QR: '.$event->title)
            ->assertSee('href="'.route('events.show', $event).'"', false)
            ->assertSee('Kembali ke Event')->assertDontSee($otherEvent->title);
        $this->assertTrue($event->is($response->viewData('fixedEvent')));
        $this->assertSame([$event->id], array_column($response->viewData('events'), 'id'));
        $this->assertSame([$day->id], array_column($response->viewData('events')[0]['days'], 'id'));
        $this->assertNotContains($otherDay->id, array_column($response->viewData('events')[0]['days'], 'id'));

        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//select[@id="scanner-event" and @disabled]/option[@selected and @value="'.$event->id.'"]')->length);
        $this->assertSame(0, $xpath->query('//select[@id="scanner-day"]/option[@selected and @value!=""]')->length);
        $this->assertStringContainsString('event='.$event->uuid, $response->viewData('validationUrl'));
        $this->assertStringContainsString('signature=', $response->viewData('validationUrl'));
    }

    public function test_scan_permission_is_sufficient_for_direct_event_scanner_access(): void
    {
        [$event] = $this->registration();
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('attendance.scan');

        $this->actingAs($user)->get(route('events.scanner', $event))->assertOk();
    }

    public function test_published_event_without_active_days_keeps_its_context_and_cannot_validate_without_a_day(): void
    {
        $event = Event::factory()->published()->create();
        $this->actingAs($this->staff());

        $page = $this->get(route('events.scanner', $event))->assertOk()
            ->assertSee($event->title)->assertSee('Event ini belum memiliki hari pelaksanaan aktif.');
        $this->assertSame([], $page->viewData('events')[0]['days']);
        $this->postJson($page->viewData('validationUrl'), ['event_id' => $event->id, 'qr_code' => 'RA1:'.Str::uuid7()])
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
    }

    public function test_scanner_permission_cannot_be_bypassed_through_event_routes_or_query_parameters(): void
    {
        [$event] = $this->registration();
        $this->actingAs($this->staff(false));

        $this->get(route('events.scanner', $event))->assertForbidden();
        $this->get(route('attendance.scanner', ['event' => $event->uuid]))->assertForbidden();
    }

    public function test_guest_and_inactive_staff_cannot_open_the_event_scanner(): void
    {
        [$event] = $this->registration();
        $this->get(route('events.scanner', $event))->assertRedirect(route('login'));
        $user = $this->staff();
        $user->is_active = false;
        $user->save();

        $this->actingAs($user)->get(route('events.scanner', $event))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[TestWith(['draft'])]
    #[TestWith(['closed'])]
    #[TestWith(['cancelled'])]
    public function test_non_published_events_cannot_be_used_even_with_a_direct_scanner_url(string $status): void
    {
        $event = Event::factory()->create(['status' => $status]);

        $this->actingAs($this->staff())->get(route('events.scanner', $event))->assertForbidden();
    }

    public function test_unknown_uuids_and_numeric_event_ids_are_not_accepted_as_event_routes(): void
    {
        [$event] = $this->registration();
        $this->actingAs($this->staff());

        $this->get(route('events.scanner', Str::uuid7()->toString()))->assertNotFound();
        $this->get(route('events.scanner', $event->id))->assertNotFound();
    }

    public function test_event_scans_use_the_existing_endpoint_and_never_write_operational_records(): void
    {
        [$event, $day, $registration] = $this->registration();
        $this->actingAs($this->staff());
        $validationUrl = $this->get(route('events.scanner', $event))->assertOk()->viewData('validationUrl');
        $this->assertSame('/attendance/scanner/validate', parse_url($validationUrl, PHP_URL_PATH));
        $attributes = $registration->fresh()->getAttributes();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson($validationUrl, $this->payload($event, $day, $registration))->assertOk()
                ->assertJsonPath('success', true)->assertJsonPath('data.participant_name', $registration->participant_name);
        }

        $this->assertSame([], $writes);
        $this->assertSame($attributes, $registration->fresh()->getAttributes());
        $this->assertDatabaseCount('event_registration_notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_event_context_rejects_a_qr_from_another_event(): void
    {
        [$event, $day] = $this->registration();
        [, , $otherRegistration] = $this->registration();
        $this->actingAs($this->staff());
        $url = $this->get(route('events.scanner', $event))->viewData('validationUrl');

        $this->postJson($url, $this->payload($event, $day, $otherRegistration))
            ->assertUnprocessable()->assertJsonValidationErrors('qr_code')->assertJsonMissingPath('data')
            ->assertDontSee($otherRegistration->participant_name);
    }

    public function test_event_context_rejects_a_day_from_another_event(): void
    {
        [$event, , $registration] = $this->registration();
        [, $otherDay] = $this->registration();
        $this->actingAs($this->staff());
        $url = $this->get(route('events.scanner', $event))->viewData('validationUrl');

        $this->postJson($url, $this->payload($event, $otherDay, $registration))
            ->assertUnprocessable()->assertJsonValidationErrors('event_day_id')->assertJsonMissingPath('data');
    }

    public function test_changing_body_event_id_cannot_change_the_signed_event_context(): void
    {
        [$event] = $this->registration();
        [$otherEvent, $otherDay, $otherRegistration] = $this->registration();
        $this->actingAs($this->staff());
        $url = $this->get(route('events.scanner', $event))->viewData('validationUrl');

        $this->postJson($url, $this->payload($otherEvent, $otherDay, $otherRegistration))
            ->assertUnprocessable()->assertJsonValidationErrors('event_id')->assertJsonMissingPath('data');
    }

    public function test_unsigned_or_tampered_event_context_is_forbidden(): void
    {
        [$event, $day, $registration] = $this->registration();
        [$otherEvent, $otherDay, $otherRegistration] = $this->registration();
        $this->actingAs($this->staff());
        $url = $this->get(route('events.scanner', $event))->viewData('validationUrl');

        $this->postJson(str_replace($event->uuid, $otherEvent->uuid, $url), $this->payload($otherEvent, $otherDay, $otherRegistration))
            ->assertForbidden()->assertJsonPath('success', false)->assertJsonMissingPath('data');
        $this->postJson(route('attendance.scanner.validate', ['event' => $event->uuid]), $this->payload($event, $day, $registration))
            ->assertForbidden()->assertJsonMissingPath('data');
    }

    public function test_event_and_permission_eligibility_are_rechecked_after_opening_the_scanner(): void
    {
        [$event, $day, $registration] = $this->registration();
        $user = $this->staff();
        $this->actingAs($user);
        $url = $this->get(route('events.scanner', $event))->viewData('validationUrl');
        $event->update(['status' => Event::STATUS_CLOSED]);

        $this->postJson($url, $this->payload($event, $day, $registration))->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $user->revokePermissionTo('attendance.scan');
        $this->postJson($url, $this->payload($event, $day, $registration))->assertForbidden()->assertJsonMissingPath('data');
    }

    public function test_generic_scanner_still_allows_selection_between_eligible_events(): void
    {
        [$firstEvent] = $this->registration();
        [$secondEvent] = $this->registration();

        $response = $this->actingAs($this->staff())->get(route('attendance.scanner'))->assertOk()
            ->assertSee($firstEvent->title)->assertSee($secondEvent->title)->assertSee('Pilih Event Published')
            ->assertDontSee('Kembali ke Event');

        $this->assertNull($response->viewData('fixedEvent'));
        $this->assertEqualsCanonicalizing([$firstEvent->id, $secondEvent->id], array_column($response->viewData('events'), 'id'));
        $this->assertSame(route('attendance.scanner.validate'), $response->viewData('validationUrl'));
    }

    private function staff(bool $canScan = true): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($canScan ? ['attendance.scan', 'events.view'] : ['events.view']);

        return $user;
    }

    /**
     * @return array{Event, EventDay, EventRegistration}
     */
    private function registration(): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        $day = EventDay::factory()->for($event)->create(['date' => '2027-01-16']);
        $registration = EventRegistration::factory()->for($event)->create();

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
