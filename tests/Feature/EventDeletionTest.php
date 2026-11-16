<?php

namespace Tests\Feature;

use App\Http\Controllers\EventController;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\EventSchedule;
use App\Models\User;
use App\Services\EventRegistrationService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('events.view', 'web');
        Permission::findOrCreate('events.delete', 'web');
    }

    public function test_empty_draft_event_can_be_deleted(): void
    {
        $event = Event::factory()->create();

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Event berhasil dihapus.');

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    #[TestWith(['employee', 'registered'])]
    #[TestWith(['employee', 'cancelled'])]
    #[TestWith(['external', 'registered'])]
    #[TestWith(['external', 'cancelled'])]
    public function test_draft_event_with_registration_history_cannot_be_deleted(string $type, string $status): void
    {
        $event = Event::factory()->create();
        $registration = EventRegistration::factory()->for($event)
            ->when($type === 'external', fn ($factory) => $factory->external())
            ->when($status === 'cancelled', fn ($factory) => $factory->cancelled())
            ->create();
        $eventAttributes = $event->fresh()->getAttributes();
        $registrationAttributes = $registration->fresh()->getAttributes();

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors(['event' => $event->deletionProhibitedReason()])
            ->assertSessionMissing('success');

        $this->assertSame($eventAttributes, $event->fresh()->getAttributes());
        $this->assertSame($registrationAttributes, $registration->fresh()->getAttributes());
        $this->assertDatabaseCount('event_registrations', 1);
    }

    #[TestWith(['pending'])]
    #[TestWith(['queued'])]
    #[TestWith(['processing'])]
    #[TestWith(['sent'])]
    #[TestWith(['failed'])]
    #[TestWith(['skipped'])]
    public function test_event_with_any_notification_history_cannot_be_deleted(string $status): void
    {
        $event = Event::factory()->create();
        $registration = EventRegistration::factory()->for($event)->cancelled()->create();
        $notification = EventRegistrationNotification::factory()->create([
            'event_id' => $event->id,
            'event_registration_id' => $registration->id,
            'status' => $status,
            'attempts' => 2,
            'metadata' => ['audit' => 'Preserve notification history'],
        ]);
        $eventAttributes = $event->fresh()->getAttributes();
        $registrationAttributes = $registration->fresh()->getAttributes();
        $notificationAttributes = $notification->fresh()->getAttributes();

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors(['event' => $event->deletionProhibitedReason()]);

        $this->assertSame($eventAttributes, $event->fresh()->getAttributes());
        $this->assertSame($registrationAttributes, $registration->fresh()->getAttributes());
        $this->assertSame($notificationAttributes, $notification->fresh()->getAttributes());
        $this->assertDatabaseCount('event_registration_notifications', 1);
    }

    public function test_notification_event_reference_is_checked_independently_of_registrations(): void
    {
        $event = Event::factory()->create();
        $notification = EventRegistrationNotification::factory()->sent()->create(['event_id' => $event->id]);
        $attributes = $notification->fresh()->getAttributes();

        $this->assertFalse($event->registrations()->exists());

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.destroy', $event))
            ->assertSessionHasErrors(['event' => $event->deletionProhibitedReason()]);

        $this->assertNotNull($event->fresh());
        $this->assertSame($attributes, $notification->fresh()->getAttributes());
        $this->assertNotNull($notification->registration->fresh());
    }

    #[TestWith(['published'])]
    #[TestWith(['closed'])]
    #[TestWith(['cancelled'])]
    public function test_non_draft_event_cannot_be_deleted_even_when_empty(string $status): void
    {
        $event = Event::factory()->create(['status' => $status]);
        $attributes = $event->fresh()->getAttributes();

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors(['event' => $event->deletionProhibitedReason()]);

        $this->assertSame($attributes, $event->fresh()->getAttributes());
    }

    #[TestWith([false, true])]
    #[TestWith([true, true])]
    #[TestWith([false, false])]
    #[TestWith([true, false])]
    public function test_event_days_and_schedules_are_preserved_on_rejected_deletion(bool $hasSchedule, bool $isActive): void
    {
        $event = Event::factory()->create();
        $day = EventDay::factory()->for($event)->create(['is_active' => $isActive]);
        $schedule = $hasSchedule ? EventSchedule::factory()->create(['event_day_id' => $day->id, 'is_active' => $isActive]) : null;
        $dayAttributes = $day->fresh()->getAttributes();
        $scheduleAttributes = $schedule?->fresh()->getAttributes();

        $this->actingAs($this->authorizedUser())
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors(['event' => $event->deletionProhibitedReason()]);

        $this->assertNotNull($event->fresh());
        $this->assertSame($dayAttributes, $day->fresh()->getAttributes());
        $this->assertSame($scheduleAttributes, $schedule?->fresh()->getAttributes());
    }

    public function test_draft_can_be_deleted_after_explicitly_removing_schedules_and_days(): void
    {
        Permission::findOrCreate('events.update', 'web');
        $user = $this->authorizedUser();
        $user->givePermissionTo('events.update');
        $event = Event::factory()->create();
        $day = EventDay::factory()->for($event)->create();
        $schedule = EventSchedule::factory()->create(['event_day_id' => $day->id]);

        $this->actingAs($user)
            ->delete(route('events.days.destroy', [$event, $day]))
            ->assertSessionHas('error', 'Event Day tidak dapat dihapus karena sudah memiliki rundown.');
        $this->delete(route('events.days.schedules.destroy', [$event, $day, $schedule]))->assertRedirect();
        $this->delete(route('events.days.destroy', [$event, $day]))->assertRedirect();
        $this->delete(route('events.destroy', $event))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        $this->assertDatabaseMissing('event_days', ['id' => $day->id]);
        $this->assertDatabaseMissing('event_schedules', ['id' => $schedule->id]);
    }

    public function test_guest_cannot_delete_event(): void
    {
        $event = Event::factory()->create();

        $this->delete(route('events.destroy', $event))->assertRedirect(route('login'));

        $this->assertNotNull($event->fresh());
    }

    public function test_user_without_delete_permission_cannot_delete_event(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('events.view');

        $this->actingAs($user)->delete(route('events.destroy', $event))->assertForbidden();

        $this->assertNotNull($event->fresh());
    }

    public function test_inactive_user_with_delete_permission_cannot_delete_event(): void
    {
        $event = Event::factory()->create();
        $user = $this->authorizedUser();
        $user->is_active = false;
        $user->save();

        $this->actingAs($user)->delete(route('events.destroy', $event))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Akun Anda telah dinonaktifkan.');

        $this->assertGuest();
        $this->assertNotNull($event->fresh());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_list_only_renders_permitted_actions_without_deletion_explanations(bool $canEdit): void
    {
        $eligibleEvent = Event::factory()->create();
        $registeredEvent = Event::factory()->create();
        EventRegistration::factory()->for($registeredEvent)->cancelled()->create();
        $notification = EventRegistrationNotification::factory()->sent()->create();
        $day = EventDay::factory()->create();
        EventSchedule::factory()->create(['event_day_id' => $day->id]);
        $protectedEvents = [
            $registeredEvent, $notification->event, $day->event,
            Event::factory()->published()->create(),
            Event::factory()->closed()->create(),
            Event::factory()->cancelled()->create(),
        ];
        $user = $this->authorizedUser();

        if ($canEdit) {
            Permission::findOrCreate('events.update', 'web');
            $user->givePermissionTo('events.update');
        }

        $response = $this->actingAs($user)->get(route('events.index'))
            ->assertOk()
            ->assertSee('data-delete-url="'.route('events.destroy', $eligibleEvent).'"', false)
            ->assertSee('btn-list flex-nowrap justify-content-end')
            ->assertDontSee('event-delete-reason-');

        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);

        foreach ([$eligibleEvent, ...$protectedEvents] as $event) {
            $actionCells = $xpath->query('//table[@id="eventsTable"]/tbody/tr[td/a[@href="'.route('events.show', $event).'"]]/td[last()]');
            $this->assertCount(1, $actionCells);
            $actionCell = $actionCells->item(0);

            $this->assertSame('Detail', trim($actionCell->textContent));
            $this->assertSame(1, $xpath->query('.//a[@href="'.route('events.show', $event).'"]', $actionCell)->length);
            $this->assertSame((int) $canEdit, $xpath->query('.//a[@href="'.route('events.edit', $event).'"]', $actionCell)->length);
            $this->assertSame((int) $event->is($eligibleEvent), $xpath->query('.//button', $actionCell)->length);
            $this->assertSame(1, $xpath->query('./*', $actionCell)->length);
            $this->assertSame(1 + (int) $canEdit + (int) $event->is($eligibleEvent), $xpath->query('./div/*', $actionCell)->length);
            $this->assertSame(0, $xpath->query('.//*[@disabled or @title or @aria-describedby or @placeholder]', $actionCell)->length);

            if (! $event->is($eligibleEvent)) {
                $response->assertDontSee('data-delete-url="'.route('events.destroy', $event).'"', false)
                    ->assertDontSee($event->deletionProhibitedReason());
            }
        }
    }

    public function test_list_hides_delete_actions_from_users_without_delete_permission(): void
    {
        Event::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('events.view');

        $this->actingAs($user)->get(route('events.index'))->assertOk()
            ->assertDontSee('aria-label="Hapus Event"', false)
            ->assertDontSee('id="deleteEventModal"', false);
    }

    public function test_rejected_deletion_shows_validation_message_on_event_list(): void
    {
        $event = Event::factory()->published()->create();

        $this->actingAs($this->authorizedUser())->followingRedirects()
            ->delete(route('events.destroy', $event))
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee($event->deletionProhibitedReason())
            ->assertDontSee('SQLSTATE');
    }

    public function test_registration_winning_race_after_route_binding_prevents_deletion(): void
    {
        $event = Event::factory()->create();
        $employee = Employee::factory()->create(['is_active' => true]);
        $user = $this->authorizedUser();
        $dispatcher = Event::getEventDispatcher();
        Event::setEventDispatcher(clone $dispatcher);
        $registered = false;

        Event::retrieved(function (Event $boundEvent) use ($event, $employee, &$registered): void {
            if ($registered || $boundEvent->id !== $event->id) {
                return;
            }

            $registered = true;
            app(EventRegistrationService::class)->registerEmployees($boundEvent, [$employee->id]);
        });

        try {
            $this->actingAs($user)->delete(route('events.destroy', $event))
                ->assertSessionHasErrors('event');
        } finally {
            Event::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($registered);
        $this->assertNotNull($event->fresh());
        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id, 'employee_id' => $employee->id, 'status' => 'registered']);
    }

    public function test_deletion_winning_race_prevents_registration_using_stale_event(): void
    {
        $event = Event::factory()->create();
        $employee = Employee::factory()->create(['is_active' => true]);

        $this->actingAs($this->authorizedUser())->delete(route('events.destroy', $event))->assertSessionHasNoErrors();

        try {
            app(EventRegistrationService::class)->registerEmployees($event, [$employee->id]);
            $this->fail('Registration must recheck that the event still exists.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Event::class, $exception->getModel());
        }

        $this->assertDatabaseCount('event_registrations', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_stale_status_and_empty_relationship_snapshots_cannot_authorize_deletion(): void
    {
        $event = Event::factory()->create();
        $event->loadExists(['registrationNotifications', 'registrations', 'days']);
        Event::query()->whereKey($event->id)->update(['status' => Event::STATUS_PUBLISHED]);
        $this->actingAs($this->authorizedUser());

        try {
            app(EventController::class)->destroy($event);
            $this->fail('Deletion must validate the current event status.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }

        $this->assertSame(Event::STATUS_PUBLISHED, $event->fresh()->status);
    }

    #[TestWith(['registration'])]
    #[TestWith(['notification'])]
    #[TestWith(['day'])]
    public function test_new_dependencies_after_eligibility_snapshot_prevent_deletion(string $dependency): void
    {
        $event = Event::factory()->create();
        $event->loadExists(['registrationNotifications', 'registrations', 'days']);
        $this->assertNull($event->deletionProhibitedReason());

        match ($dependency) {
            'registration' => EventRegistration::factory()->for($event)->cancelled()->create(),
            'notification' => EventRegistrationNotification::factory()->sent()->create(['event_id' => $event->id]),
            'day' => EventDay::factory()->for($event)->create(),
        };

        $this->actingAs($this->authorizedUser());

        try {
            app(EventController::class)->destroy($event);
            $this->fail('Deletion must recheck dependencies after locking the event.');
        } catch (ValidationException $exception) {
            $this->assertSame([$event->fresh()->deletionProhibitedReason()], $exception->errors()['event']);
        }

        $this->assertNotNull($event->fresh());
        $this->assertSame(1, match ($dependency) {
            'registration' => $event->registrations()->count(),
            'notification' => $event->registrationNotifications()->count(),
            'day' => $event->days()->count(),
        });
    }

    public function test_registration_and_deletion_both_lock_event_inside_transaction(): void
    {
        $registrationEvent = Event::factory()->create();
        $deletionEvent = Event::factory()->create();
        $employee = Employee::factory()->create(['is_active' => true]);
        $user = $this->authorizedUser();
        $scopes = Event::getAllGlobalScopes();
        $transactionLevel = DB::transactionLevel();
        $lockedReads = 0;

        Event::addGlobalScope('observe_event_lock', function (Builder $query) use ($transactionLevel, &$lockedReads): void {
            if ($query->getQuery()->lock === true) {
                $this->assertGreaterThan($transactionLevel, DB::transactionLevel());
                $lockedReads++;
            }
        });

        try {
            app(EventRegistrationService::class)->registerEmployees($registrationEvent, [$employee->id]);
            $this->actingAs($user)->delete(route('events.destroy', $deletionEvent))->assertSessionHasNoErrors();
        } finally {
            Event::setAllGlobalScopes($scopes);
        }

        $this->assertSame(2, $lockedReads);
        $this->assertNotNull($registrationEvent->fresh());
        $this->assertDatabaseMissing('events', ['id' => $deletionEvent->id]);
    }

    public function test_unexpected_foreign_key_restriction_rolls_back_and_returns_validation_error(): void
    {
        $event = Event::factory()->create();
        $employee = Employee::factory()->create();
        $user = $this->authorizedUser();
        $dispatcher = Event::getEventDispatcher();
        Event::setEventDispatcher(clone $dispatcher);

        Event::deleting(function (Event $deletingEvent) use ($employee): void {
            EventRegistration::factory()->for($deletingEvent)->create(['employee_id' => $employee->id]);
        });

        try {
            $this->actingAs($user)->delete(route('events.destroy', $event))
                ->assertRedirect(route('events.index'))
                ->assertSessionHasErrors('event')
                ->assertSessionMissing('success');
        } finally {
            Event::setEventDispatcher($dispatcher);
        }

        $this->assertNotNull($event->fresh());
        $this->assertDatabaseCount('event_registrations', 0);
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    #[TestWith([1451, true])]
    #[TestWith([1062, false])]
    public function test_mysql_foreign_key_failure_is_translated_without_hiding_other_database_errors(int $errorCode, bool $isForeignKeyError): void
    {
        $event = Event::factory()->create();
        $user = $this->authorizedUser();
        $dispatcher = Event::getEventDispatcher();
        Event::setEventDispatcher(clone $dispatcher);
        $previous = new PDOException('Database constraint failure.');
        $previous->errorInfo = ['23000', $errorCode, 'Database constraint failure.'];
        $failure = new QueryException('mysql', 'delete from events where id = ?', [$event->id], $previous);
        Event::deleting(function () use ($failure): void {
            throw $failure;
        });

        try {
            if ($isForeignKeyError) {
                $this->actingAs($user)->delete(route('events.destroy', $event))->assertSessionHasErrors('event');
            } else {
                $this->withoutExceptionHandling()->actingAs($user);

                try {
                    $this->delete(route('events.destroy', $event));
                    $this->fail('Unrelated database errors must not be disguised as deletion validation.');
                } catch (QueryException $exception) {
                    $this->assertSame($failure, $exception);
                }
            }
        } finally {
            Event::setEventDispatcher($dispatcher);
        }

        $this->assertNotNull($event->fresh());
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['events.view', 'events.delete']);

        return $user;
    }
}
