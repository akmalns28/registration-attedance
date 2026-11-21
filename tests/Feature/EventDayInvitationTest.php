<?php

namespace Tests\Feature;

use App\Jobs\SendEventRegistrationNotificationJob;
use App\Mail\EventRegistrationMail;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\AnalyticService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EventDayInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['mail.default' => 'smtp']);
        Queue::fake();
        Mail::fake();
    }

    public function test_batch_registration_assigns_selected_days_and_all_days_without_duplicate_registrations_or_invitations(): void
    {
        [$event, $first, $second] = $this->event();
        EventDay::factory()->for($event)->inactive()->create(['day_number' => 3, 'date' => '2027-01-17']);
        $employees = Employee::factory()->count(2)->create();
        $externals = ExternalParticipant::factory()->count(2)->create();
        $url = route('events.participants.store', $event);
        $this->actingAs($this->staff());

        $this->get(route('events.show', $event))->assertOk()->assertSee('All Days')->assertSee('Selected Days');
        $employeeSelection = ['participant_type' => 'employee', 'participant_ids' => $employees->modelKeys(), 'day_selection' => 'selected', 'day_ids' => [$second->id]];
        $this->post($url, $employeeSelection)->assertSessionHasNoErrors();
        $this->post($url, $employeeSelection)->assertSessionHasNoErrors();
        $this->post($url, ['participant_type' => 'external', 'participant_ids' => $externals->modelKeys(), 'day_selection' => 'all'])->assertSessionHasNoErrors();

        $this->assertSame(4, $event->registrations()->count());
        $this->assertSame(6, $first->invitations()->count() + $second->invitations()->count());
        $this->assertSame(2, $first->invitations()->count());
        $this->assertSame(4, $second->invitations()->count());
        $this->assertDatabaseCount('event_day_invitations', 6);
        $this->assertDatabaseCount('event_registration_notifications', 4);
    }

    public function test_existing_client_without_day_selection_creates_no_eligibility_and_staff_can_assign_days_later(): void
    {
        [$event, $day] = $this->event();
        $employee = Employee::factory()->create(['email' => 'participant@example.test']);
        $this->actingAs($this->staff());
        $this->post(route('events.participants.store', $event), ['participant_type' => 'employee', 'participant_ids' => [$employee->id]])->assertSessionHasNoErrors();
        $registration = $event->registrations()->sole();

        $this->assertFalse($registration->invitedDays()->exists());
        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $registration->latestInvitation->status);
        Queue::assertNothingPushed();
        $this->put(route('events.participants.invitations.update', [$event, $registration]), ['day_ids' => [$day->id]])->assertSessionHasNoErrors();
        $this->assertSame([$day->id], $registration->invitedDays()->pluck('event_days.id')->all());
        Queue::assertNothingPushed();
    }

    public function test_batch_reactivation_requires_retaining_attended_days_and_keeps_the_original_registration(): void
    {
        [$event, $first, $second] = $this->event();
        $registration = EventRegistration::factory()->for($event)->invitedTo($first)->cancelled()->create();
        Attendance::factory()->for($registration, 'registration')->for($first)->create();
        $this->actingAs($this->staff());

        $payload = [
            'participant_type' => 'employee', 'participant_ids' => [$registration->employee_id],
            'day_selection' => 'selected', 'day_ids' => [$second->id],
        ];
        $this->postJson(route('events.participants.store', $event), $payload)->assertUnprocessable()->assertJsonValidationErrors('day_ids');
        $this->assertTrue($registration->fresh()->isCancelled());
        $this->post(route('events.participants.store', $event), [...$payload, 'day_ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();

        $this->assertSame($registration->uuid, $event->registrations()->sole()->uuid);
        $this->assertTrue($registration->fresh()->isRegistered());
        $this->assertSame([$first->id, $second->id], $registration->invitedDays()->pluck('event_days.id')->all());
        $this->assertDatabaseHas('attendances', ['event_registration_id' => $registration->id, 'event_day_id' => $first->id]);
    }

    public function test_wrong_event_and_inactive_day_assignments_and_unauthorized_management_are_rejected(): void
    {
        [$event, $day] = $this->event();
        [$otherEvent, $otherDay] = $this->event();
        $otherDay->update(['is_active' => false]);
        $inactive = EventDay::factory()->for($event)->inactive()->create(['day_number' => 3, 'date' => '2027-01-17']);
        $registration = EventRegistration::factory()->for($event)->create();
        $url = route('events.participants.invitations.update', [$event, $registration]);

        $this->actingAs($this->staff('panitia'))->putJson($url, ['day_ids' => [$day->id]])->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->get(route('events.show', $event))->assertOk()->assertDontSee('data-event-day-invitation-modal', false);
        $this->actingAs($this->staff())->putJson(route('events.participants.invitations.update', [$otherEvent, $registration]), ['day_ids' => [$otherDay->id]])->assertNotFound();
        $this->getJson(route('events.participants.invitations.edit', [$otherEvent, $registration]))->assertNotFound();
        $this->putJson($url, ['day_ids' => [$otherDay->id]])->assertUnprocessable()->assertJsonValidationErrors('day_ids');
        $this->putJson($url, ['day_ids' => [$inactive->id]])->assertUnprocessable()->assertJsonValidationErrors('day_ids');
        $this->postJson(route('events.participants.store', $event), [
            'participant_type' => 'employee', 'participant_ids' => [Employee::factory()->create()->id], 'day_selection' => 'selected', 'day_ids' => [$otherDay->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('day_ids');
        $this->assertDatabaseCount('event_day_invitations', 0);
        $this->assertSame(1, $event->registrations()->count());
    }

    #[TestWith(['employee'])]
    #[TestWith(['external'])]
    public function test_modal_loads_all_days_and_saves_an_updated_summary_for_each_participant_type(string $type): void
    {
        [$event, $first, $second] = $this->event();
        $inactive = EventDay::factory()->for($event)->inactive()->create(['day_number' => 3, 'date' => '2027-01-17']);
        $registration = EventRegistration::factory()->for($event)->invitedTo($first)
            ->when($type === 'external', fn ($factory) => $factory->external())->create();
        $another = EventRegistration::factory()->for($event)->create();
        $this->actingAs($this->staff());
        $url = route('events.participants.invitations.edit', [$event, $registration]);
        $page = $this->get(route('events.show', $event))->assertOk()
            ->assertSee('data-invitation-url="'.route('events.participants.invitations.edit', [$event, $another], absolute: false).'"', false)
            ->assertSee('data-invitation-url="'.route('events.participants.invitations.edit', [$event, $registration], absolute: false).'"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'id="eventDayInvitationModal"'));
        $this->get(route('events.days.show', [$event, $first]))->assertOk()->assertSee('id="eventDayInvitationModal"', false)
            ->assertSee('data-invitation-url="'.route('events.participants.invitations.edit', [$event, $registration], absolute: false).'"', false);

        $this->getJson($url)->assertOk()->assertJsonCount(3, 'days')
            ->assertJsonPath('participant_name', $registration->participant_name)->assertJsonPath('event_name', $event->title)
            ->assertJsonPath('days.0', ['id' => $first->id, 'number' => 1, 'date' => '15/01/2027', 'title' => 'First Day', 'assigned' => true, 'available' => true, 'protected' => false])
            ->assertJsonPath('days.1.assigned', false)->assertJsonPath('days.2.id', $inactive->id)->assertJsonPath('days.2.available', false);
        $this->putJson($url, ['day_ids' => [$first->id, $second->id]])->assertOk()
            ->assertJsonPath('day_ids', [$first->id, $second->id])->assertJsonPath('days_label', 'Day 1, Day 2');
        $this->putJson($url, ['day_ids' => [$second->id]])->assertOk()->assertJsonPath('day_ids', [$second->id])
            ->assertJsonPath('days_label', 'Day 2')->assertJsonPath('message', 'Hari undangan peserta diperbarui.');
        $this->getJson($url)->assertOk()->assertJsonPath('days.0.assigned', false)->assertJsonPath('days.1.assigned', true);
        $this->assertFalse($another->invitedDays()->exists());
    }

    public function test_qr_and_manual_check_in_use_day_assignments_and_attended_days_cannot_be_revoked(): void
    {
        [$event, $first, $second] = $this->event();
        $registration = EventRegistration::factory()->for($event)->create();
        $registration->invitedDays()->attach($second);
        $this->actingAs($this->staff());
        $this->travelTo('2027-01-15 09:00:00');
        $qr = ['event_id' => $event->id, 'event_day_id' => $first->id, 'qr_code' => 'RA1:'.$registration->uuid];
        $manual = ['event_id' => $event->id, 'event_day_id' => $first->id, 'registration_id' => $registration->id];

        $this->postJson(route('attendance.scanner.validate'), $qr)->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->postJson(route('attendance.manual.check-in'), $manual)->assertUnprocessable()->assertJsonValidationErrors('event_day_id');
        $this->getJson(route('attendance.manual.search', [
            'event_id' => $event->id, 'event_day_id' => $first->id, 'search' => $registration->employee->nip, 'participant_type' => 'employee',
        ]))->assertOk()->assertJsonCount(0, 'data');

        $this->put(route('events.participants.invitations.update', [$event, $registration]), ['day_ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->postJson(route('attendance.check-in'), $qr)->assertCreated();
        $this->postJson(route('attendance.manual.check-in'), $manual)->assertOk()->assertJsonPath('created', false);
        $this->travelTo('2027-01-16 09:00:00');
        $this->postJson(route('attendance.manual.check-in'), [...$manual, 'event_day_id' => $second->id])->assertCreated();

        $url = route('events.participants.invitations.update', [$event, $registration]);
        $this->getJson($url)->assertOk()->assertJsonPath('days.1.assigned', true)->assertJsonPath('days.1.protected', true);
        $this->putJson($url, ['day_ids' => [$first->id]])->assertUnprocessable()
            ->assertJsonValidationErrors(['day_ids' => 'Hari dengan kehadiran tercatat tidak dapat dicabut.']);
        $this->postJson(route('attendance.check-in'), [...$qr, 'event_day_id' => $second->id])->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('attendances', 2);
        $this->assertSame([$first->id, $second->id], $registration->invitedDays()->pluck('event_days.id')->all());
    }

    public function test_legacy_revoked_day_with_attendance_remains_visible_and_unassigned(): void
    {
        [$event, $first, $second] = $this->event();
        $registration = EventRegistration::factory()->for($event)->invitedTo($first, $second)->create();
        Attendance::factory()->for($registration, 'registration')->for($second)->create();
        DB::table('event_day_invitations')->where('event_registration_id', $registration->id)
            ->where('event_day_id', $second->id)->update(['revoked_at' => now()]);
        $this->actingAs($this->staff());

        $url = route('events.participants.invitations.update', [$event, $registration]);
        $this->getJson($url)->assertOk()->assertJsonPath('days.1.assigned', false)->assertJsonPath('days.1.protected', false);
        $this->putJson($url, ['day_ids' => [$first->id]])->assertOk()->assertJsonPath('day_ids', [$first->id]);
        $this->assertDatabaseCount('attendances', 1);
        $this->getJson(route('events.days.attendance', [$event, $second]))->assertOk()
            ->assertJsonPath('summary.active_registrations', 0)->assertJsonPath('summary.checked_in', 0)->assertJsonCount(1, 'attendees');
        $this->get(route('events.days.show', [$event, $second]))->assertOk()->assertSee('Peserta Diundang')->assertSee('Undangan dicabut');
    }

    public function test_report_dashboard_and_analytics_use_the_same_invitation_slots(): void
    {
        [$event, $first, $second] = $this->event();
        [$both, $one, $cancelled] = EventRegistration::factory()->for($event)->count(3)->create()->all();
        $both->invitedDays()->attach([$first->id, $second->id]);
        $one->invitedDays()->attach($second);
        $cancelled->invitedDays()->attach($first);
        $cancelled->update(['status' => EventRegistration::STATUS_CANCELLED]);
        $this->actingAs($this->staff());
        Attendance::factory()->for($both, 'registration')->for($second)->create();
        Attendance::factory()->for($cancelled, 'registration')->for($first)->create();

        $this->getJson(route('events.reports.data', $event))->assertOk()
            ->assertJsonPath('recordsTotal', 3)->assertJsonPath('summary.present', 1)->assertJsonPath('summary.percentage', 33.33);
        $this->getJson(route('events.reports.data', ['event' => $event, 'event_day' => $second->id]))->assertOk()
            ->assertJsonPath('summary.expected', 2)->assertJsonPath('summary.percentage', 50);
        $dashboard = $this->get(route('dashboard', ['event' => $event->uuid]))->assertOk()->viewData('stats');
        $this->assertSame(2, $dashboard['total_registered']);
        $this->assertSame(3, $dashboard['day_invitations']);
        $this->assertSame(1, $dashboard['total_attendance']);
        $this->assertSame(33.33, $dashboard['attendance_percentage']);
        $analytics = app(AnalyticService::class)->data(['event' => $event->uuid]);
        $this->assertSame([1, 2], array_column($analytics['charts']['by_day'], 'expected'));
        $this->assertSame([0.0, 50.0], array_column($analytics['charts']['by_day'], 'percentage'));
    }

    public function test_preview_and_queued_mail_show_current_assigned_dates_and_send_and_resend_requests_are_idempotent(): void
    {
        [$event, $first, $second] = $this->event();
        $registration = EventRegistration::factory()->for($event)->for(Employee::factory()->state(['email' => 'participant@example.test']))->create();
        $registration->invitedDays()->attach($first);
        $this->actingAs($this->staff());
        $send = route('events.participants.invitations.send', [$event, $registration]);
        $preview = route('events.participants.invitations.preview', [$event, $registration]);
        $this->get($preview)->assertOk()->assertSee('15 Januari 2027')->assertDontSee('16 Januari 2027')
            ->assertDontSee('First Day')->assertDontSee('Second Day');
        $this->post($send)->assertRedirect();
        $this->post($send)->assertRedirect();
        $notification = $registration->notifications()->sole();
        $this->put(route('events.participants.invitations.update', [$event, $registration]), ['day_ids' => [$second->id]])->assertSessionHasNoErrors();
        $this->assertSame([$second->id], $registration->invitedDays()->pluck('event_days.id')->all());
        $this->get($preview)->assertOk()->assertSee('16 Januari 2027')->assertDontSee('15 Januari 2027')
            ->assertDontSee('First Day')->assertDontSee('Second Day');
        $job = new SendEventRegistrationNotificationJob($notification->id, $notification->metadata['dispatch_generation']);
        $job->handle();
        $job->handle();

        Mail::assertSent(EventRegistrationMail::class, 1);
        Mail::assertSent(EventRegistrationMail::class, function (EventRegistrationMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, '16 Januari 2027') && ! str_contains($html, '15 Januari 2027') && ! str_contains($html, 'First Day')
                && ! str_contains($html, 'Second Day') && count($mail->attachments()) === 1;
        });
        $resend = route('notifications.resend', $notification);
        $this->post($resend)->assertRedirect();
        $this->post($resend)->assertRedirect();
        $this->assertSame(2, $registration->notifications()->count());
    }

    public function test_queued_invitation_is_skipped_if_all_assigned_days_are_revoked_before_delivery(): void
    {
        [$event, $day] = $this->event();
        $registration = EventRegistration::factory()->for($event)
            ->for(Employee::factory()->state(['email' => 'participant@example.test']))->invitedTo($day)->create();
        $this->actingAs($this->staff());
        $this->post(route('events.participants.invitations.send', [$event, $registration]))->assertSessionHasNoErrors();
        $notification = $registration->notifications()->sole();

        $this->put(route('events.participants.invitations.update', [$event, $registration]), [])->assertSessionHasNoErrors();
        (new SendEventRegistrationNotificationJob($notification->id, $notification->metadata['dispatch_generation']))->handle();

        Mail::assertNothingSent();
        $this->assertSame(EventRegistrationNotification::STATUS_SKIPPED, $notification->fresh()->status);
        $this->assertSame('Peserta belum memiliki hari undangan aktif.', $notification->fresh()->last_error);
    }

    public function test_backfill_uses_historical_attendance_and_a_clear_single_day_without_assigning_other_days(): void
    {
        [$multi, $first, $second] = $this->event();
        $walkIn = EventRegistration::factory()->for($multi)->cancelled()->create();
        $unassigned = EventRegistration::factory()->for($multi)->create();
        Attendance::factory()->for($walkIn, 'registration')->for($second)->create();
        $single = Event::factory()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-15']);
        $onlyDay = EventDay::factory()->for($single)->create();
        $singleRegistration = EventRegistration::factory()->for($single)->create();
        $migration = require database_path('migrations/2026_11_20_095339_create_event_day_invitations_table.php');

        $migration->down();
        $migration->up();

        $this->assertSame([$second->id], $walkIn->invitedDays()->pluck('event_days.id')->all());
        $this->assertFalse($unassigned->invitedDays()->exists());
        $this->assertFalse($first->invitations()->exists());
        $this->assertSame([$onlyDay->id], $singleRegistration->invitedDays()->pluck('event_days.id')->all());
        $this->assertDatabaseCount('attendances', 1);
    }

    /** @return array{Event, EventDay, EventDay} */
    private function event(): array
    {
        $event = Event::factory()->published()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);

        return [
            $event,
            EventDay::factory()->for($event)->create(['title' => 'First Day']),
            EventDay::factory()->for($event)->create(['day_number' => 2, 'date' => '2027-01-16', 'title' => 'Second Day']),
        ];
    }

    private function staff(string $role = 'event-admin'): User
    {
        return User::factory()->create(['is_active' => true])->assignRole($role);
    }
}
