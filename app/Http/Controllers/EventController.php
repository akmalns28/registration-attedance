<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\User;
use App\Services\EventAssetStorage;
use App\Services\EventDayAttendanceService;
use App\Services\EventInvitationExportQueue;
use App\Services\EventPosterService;
use App\Services\EventPublicationNotificationService;
use App\Services\OrganizationFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class EventController extends Controller
{
    private const MAX_EVENT_DAY_NUMBER = 65_535;

    private const POSTER_FIELDS = ['posters', 'poster_remove', 'poster_order', 'poster_primary'];

    public function __construct(
        private readonly EventPublicationNotificationService $notifications,
        private readonly EventAssetStorage $assets,
        private readonly EventPosterService $posters,
    ) {}

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
        $data = $request->safe()->except(['logo', 'auto_create_event_days', ...self::POSTER_FIELDS]);
        $posterChanges = $request->safe()->only(self::POSTER_FIELDS);
        $createEventDays = $request->boolean('auto_create_event_days');
        $logoPath = $this->storeLogo($request->file('logo'));

        try {
            [$event, $discardedPosters] = $this->posters->saveUploads(
                $request->file('posters', []),
                fn (array $uploads): array => DB::transaction(function () use ($data, $logoPath, $createEventDays, $uploads, $posterChanges): array {
                    $event = Event::create([...$data, 'logo_path' => $logoPath]);

                    if ($createEventDays) {
                        $this->createEventDays($event);
                    }

                    return [$event, $this->posters->sync($event, $uploads, $posterChanges)];
                }, attempts: 3),
            );
        } catch (Throwable $exception) {
            $this->deleteLogo($logoPath);

            throw $exception;
        }

        $this->posters->deleteFiles($discardedPosters);

        return redirect()->route('events.show', $event)->with('success', 'Event berhasil dibuat.');
    }

    private function createEventDays(Event $event): void
    {
        $startDate = $event->start_date;
        $dayCount = (int) $startDate->diffInDays($event->end_date) + 1;
        if ($dayCount > self::MAX_EVENT_DAY_NUMBER) {
            throw ValidationException::withMessages(['end_date' => 'Rentang tanggal melebihi jumlah Event Day yang dapat disimpan.']);
        }

        $timestamp = now();
        $days = [];
        for ($dayNumber = 1, $date = $startDate->copy(); $dayNumber <= $dayCount; $dayNumber++, $date->addDay()) {
            $days[] = [
                'event_id' => $event->id,
                'day_number' => $dayNumber,
                'date' => $date->toDateString(),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($days) === 200 || $dayNumber === $dayCount) {
                EventDay::query()->insert($days);
                $days = [];
            }
        }
    }

    /**
     * Display event details.
     */
    public function show(Request $request, Event $event, EventDayAttendanceService $attendance, EventInvitationExportQueue $exports): View
    {
        /*
    |--------------------------------------------------------------------------
    | Event Days & Rundown
    |--------------------------------------------------------------------------
    */

        $event->load(['days' => fn (HasMany $days): HasMany => $days
            ->withCount('schedules')
            ->withMin('schedules', 'start_time')
            ->withMax('schedules', 'end_time')]);

        $event->loadCount([
            'registrations as registered_participants_count' => fn ($query) => $query->registered(),
            'registrations as registered_employees_count' => fn ($query) => $query->registered()->employees(),
            'registrations as registered_externals_count' => fn ($query) => $query->registered()->externals(),
            'registrations as cancelled_registrations_count' => fn ($query) => $query->cancelled(),
        ]);

        $dayCounts = $attendance->countsByDay($event);
        foreach ($event->days as $day) {
            $day->invited_count = (int) ($dayCounts->get($day->id)?->invited ?? 0);
            $day->checked_in_count = (int) ($dayCounts->get($day->id)?->checked_in ?? 0);
        }
        $expectedAttendance = (int) $event->days->sum('invited_count');
        $totalCheckedIn = (int) $event->days->sum('checked_in_count');
        $eventAttendanceSummary = [
            'checked_in' => $totalCheckedIn,
            'expected_attendance' => $expectedAttendance,
            'percentage' => $expectedAttendance === 0 ? 0.0 : round($totalCheckedIn / $expectedAttendance * 100, 2),
        ];

        /*
    |--------------------------------------------------------------------------
    | Participant Registrations
    |--------------------------------------------------------------------------
    */

        $registrations = $event
            ->registrations()
            ->with(['employee.department', 'externalParticipant', 'invitedDays'])
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->get();

        /*
    |--------------------------------------------------------------------------
    | Remaining Capacity
    |--------------------------------------------------------------------------
    */

        $remainingCapacity = $event->capacity === null ? null : max(0, $event->capacity - $event->registered_participants_count);

        $user = auth()->user();
        $canAddParticipants = $user->can('events.update') && in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED], true);
        $canViewReports = $user->can('reports.view');
        $organizationOptions = $canAddParticipants || $canViewReports ? OrganizationFilters::options() : [];
        $event->load('posters');
        $galleryFilter = $request->query('media_type');
        $galleryFilter = in_array($galleryFilter, ['image', 'video'], true) ? $galleryFilter : 'all';
        $gallery = $event->media()
            ->when($galleryFilter !== 'all', fn ($query) => $query->where('media_type', $galleryFilter))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate(config('event_assets.gallery_per_page'), pageName: 'gallery_page')
            ->appends(['media_type' => $galleryFilter])->fragment('event-gallery');

        return view('events.show', [
            'event' => $event,
            'registrations' => $registrations,
            'canAddParticipants' => $canAddParticipants,
            'canResendInvitations' => $event->isPublished() && $user->can('events.update'),
            'organizationOptions' => $organizationOptions,
            'remainingCapacity' => $remainingCapacity,
            'eventAttendanceSummary' => $eventAttendanceSummary,
            'gallery' => $gallery,
            'galleryFilter' => $galleryFilter,
            'invitationExports' => $user->can('registrations.manage') ? $exports->recent($event, $user) : [],
        ]);
    }

    public function logo(Event $event): Response
    {
        Gate::allowIf(fn (User $user): bool => $user->canAny(['events.view', 'events.update']));
        $logo = $event->logoImage();
        abort_if($logo === null, 404);

        return response($logo['contents'], 200, [
            'Content-Type' => $logo['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Show edit event form.
     */
    public function edit(Event $event): View
    {
        $event->load('posters');
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
        $data = $request->safe()->except(['logo', ...self::POSTER_FIELDS]);
        $posterChanges = $request->safe()->only(self::POSTER_FIELDS);
        $logoPath = $this->storeLogo($request->file('logo'));
        if ($logoPath !== null) {
            $data['logo_path'] = $logoPath;
        }

        /*
    |--------------------------------------------------------------------------
    | Update Event Within Transaction
    |--------------------------------------------------------------------------
    |
    | Lock the Event row to prevent race conditions with registrations.
    | Persist invitations with the event's publication transition.
    |
    */

        try {
            [$previousLogo, $discardedPosters] = $this->posters->saveUploads(
                $request->file('posters', []),
                fn (array $uploads): array => $this->updateEvent($event, $data, $uploads, $posterChanges),
            );
        } catch (Throwable $exception) {
            $this->deleteLogo($logoPath);

            throw $exception;
        }

        $this->deleteLogo($previousLogo);
        $this->posters->deleteFiles($discardedPosters);

        return redirect()->route('events.show', $event)->with('success', 'Event berhasil diperbarui.');
    }

    private function updateEvent(Event $event, array $data, array $uploads, array $posterChanges): array
    {
        return DB::transaction(function () use ($event, $data, $uploads, $posterChanges): array {
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
            $previousLogo = $lockedEvent->logo_path;

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
            $discardedPosters = $uploads !== [] || $posterChanges !== []
                ? $this->posters->sync($lockedEvent, $uploads, $posterChanges)
                : [];

            /*
        |--------------------------------------------------------------------------
        | Detect Publication Transition
        |--------------------------------------------------------------------------
        */

            if ($previousStatus === Event::STATUS_DRAFT && $lockedEvent->status === Event::STATUS_PUBLISHED) {
                $this->notifications->scheduleForPublishedEvent($lockedEvent);
            }

            return [$lockedEvent->wasChanged('logo_path') ? $previousLogo : null, $discardedPosters];
        }, attempts: 3);
    }

    private function storeLogo(?UploadedFile $logo): ?string
    {
        if ($logo === null) {
            return null;
        }

        $path = $logo->store('event-logos', ['disk' => 'local', 'visibility' => 'private']);
        if ($path === false) {
            throw ValidationException::withMessages(['logo' => 'Logo event gagal disimpan. Silakan coba kembali.']);
        }

        return $path;
    }

    private function deleteLogo(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Delete event.
     */
    public function destroy(Event $event): RedirectResponse
    {
        Gate::authorize('events.delete');

        try {
            [$logoPath, $posterPaths, $mediaPaths] = DB::transaction(function () use ($event): array {
                $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                $lockedEvent->loadExists(['registrationNotifications', 'registrations', 'days']);

                $reason = $lockedEvent->deletionProhibitedReason();

                if ($reason !== null) {
                    throw ValidationException::withMessages(['event' => $reason])
                        ->redirectTo(route('events.index'));
                }

                $posterPaths = $lockedEvent->posters()->pluck('file_path')->all();
                $mediaPaths = $lockedEvent->media()->pluck('file_path')->all();
                $lockedEvent->delete();

                return [$lockedEvent->logo_path, $posterPaths, $mediaPaths];
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

        $this->deleteLogo($logoPath);
        $this->posters->deleteFiles($posterPaths);
        $this->assets->delete($mediaPaths);

        return redirect()->route('events.index')->with('success', 'Event berhasil dihapus.');
    }
}
