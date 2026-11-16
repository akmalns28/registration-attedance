<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Models\Employee;
use App\Models\Event;
use App\Models\ExternalParticipant;
use App\Services\EventPublicationNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function __construct(private readonly EventPublicationNotificationService $notifications) {}

    /**
     * Display event list.
     */
    public function index(): View
    {
        $events = Event::query()
            ->withExists(['registrationNotifications', 'registrations', 'days'])
            ->orderByDesc('start_date')->get();

        return view('events.index', [
            'events' => $events,
        ]);
    }

    /**
     * Show create event form.
     */
    public function create(): View
    {
        return view('events.create');
    }

    /**
     * Store new event.
     */
    public function store(StoreEventRequest $request): RedirectResponse
    {
        $event = Event::create($request->validated());

        return redirect()->route('events.show', $event)->with('success', 'Event berhasil dibuat.');
    }

    /**
     * Display event details.
     */
    public function show(Event $event): View
    {
        /*
    |--------------------------------------------------------------------------
    | Event Days & Rundown
    |--------------------------------------------------------------------------
    */

        $event->load(['days.schedules']);

        $event->loadCount([
            'registrations as registered_participants_count' => fn ($query) => $query->registered(),
            'registrations as registered_employees_count' => fn ($query) => $query->registered()->employees(),
            'registrations as registered_externals_count' => fn ($query) => $query->registered()->externals(),
            'registrations as cancelled_registrations_count' => fn ($query) => $query->cancelled(),
        ]);

        /*
    |--------------------------------------------------------------------------
    | Participant Registrations
    |--------------------------------------------------------------------------
    */

        $registrations = $event
            ->registrations()
            ->with(['employee.department', 'externalParticipant'])
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->get();

        /*
    |--------------------------------------------------------------------------
    | Participant Master Data
    |--------------------------------------------------------------------------
    |
    | Only users with events.update can access participant
    | selection lists.
    |
    */

        $employees = collect();
        $externalParticipants = collect();

        if (auth()->user()->can('events.update')) {
            $employees = Employee::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'nip', 'name']);

            $externalParticipants = ExternalParticipant::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'organization']);
        }

        /*
    |--------------------------------------------------------------------------
    | Remaining Capacity
    |--------------------------------------------------------------------------
    */

        $remainingCapacity = $event->capacity === null ? null : max(0, $event->capacity - $event->registered_participants_count);

        return view('events.show', [
            'event' => $event,
            'registrations' => $registrations,
            'employees' => $employees,
            'externalParticipants' => $externalParticipants,
            'remainingCapacity' => $remainingCapacity,
        ]);
    }

    /**
     * Show edit event form.
     */
    public function edit(Event $event): View
    {
        $event->loadCount([
            'registrations as registered_participants_count' => fn ($query) => $query->registered(),
        ]);

        return view('events.edit', [
            'event' => $event,
        ]);
    }

    /**
     * Update event information.
     */
    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        $data = $request->validated();

        /*
    |--------------------------------------------------------------------------
    | Update Event Within Transaction
    |--------------------------------------------------------------------------
    |
    | Lock the Event row to prevent race conditions with registrations.
    | Persist invitations with the event's publication transition.
    |
    */

        DB::transaction(function () use ($event, $data): void {
            /*
        |--------------------------------------------------------------------------
        | Lock Event
        |--------------------------------------------------------------------------
        */

            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            /*
        |--------------------------------------------------------------------------
        | Track Previous Status
        |--------------------------------------------------------------------------
        */

            $previousStatus = $lockedEvent->status;

            if ($data['status'] === Event::STATUS_PUBLISHED && ! in_array($previousStatus, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED], true)) {
                throw ValidationException::withMessages(['status' => 'Event Closed atau Cancelled tidak dapat dipublikasikan kembali.']);
            }

            /*
        |--------------------------------------------------------------------------
        | Validate Event Days
        |--------------------------------------------------------------------------
        */

            $outsideRange = $lockedEvent
                ->days()
                ->where(function ($query) use ($data) {
                    $query->whereDate('date', '<', $data['start_date'])->orWhereDate('date', '>', $data['end_date']);
                })
                ->exists();

            if ($outsideRange) {
                throw ValidationException::withMessages([
                    'start_date' => 'Rentang tanggal tidak boleh mengeluarkan hari pelaksanaan yang sudah dibuat.',
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Validate Capacity
        |--------------------------------------------------------------------------
        */

            $registeredCount = $lockedEvent->registrations()->registered()->count();

            $newCapacity = $data['capacity'] === null ? null : (int) $data['capacity'];

            if ($newCapacity !== null && $newCapacity < $registeredCount) {
                throw ValidationException::withMessages([
                    'capacity' => "Kapasitas tidak boleh kurang dari jumlah peserta terdaftar ({$registeredCount} peserta).",
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Update Event
        |--------------------------------------------------------------------------
        */

            $lockedEvent->update($data);

            /*
        |--------------------------------------------------------------------------
        | Detect Publication Transition
        |--------------------------------------------------------------------------
        */

            if ($previousStatus === Event::STATUS_DRAFT && $lockedEvent->status === Event::STATUS_PUBLISHED) {
                $this->notifications->scheduleForPublishedEvent($lockedEvent);
            }
        }, attempts: 3);

        return redirect()->route('events.show', $event)->with('success', 'Event berhasil diperbarui.');
    }

    /**
     * Delete event.
     */
    public function destroy(Event $event): RedirectResponse
    {
        Gate::authorize('events.delete');

        try {
            DB::transaction(function () use ($event): void {
                $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                $lockedEvent->loadExists(['registrationNotifications', 'registrations', 'days']);

                $reason = $lockedEvent->deletionProhibitedReason();

                if ($reason !== null) {
                    throw ValidationException::withMessages(['event' => $reason])
                        ->redirectTo(route('events.index'));
                }

                $lockedEvent->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1451
                && ! str_contains($exception->errorInfo[2] ?? '', 'FOREIGN KEY constraint failed')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'event' => 'Event tidak dapat dihapus karena masih memiliki data operasional terkait. Gunakan status Cancelled untuk membatalkan event dan menjaga riwayat operasional.',
            ])->redirectTo(route('events.index'));
        }

        return redirect()->route('events.index')->with('success', 'Event berhasil dihapus.');
    }
}
