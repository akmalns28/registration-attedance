<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventParticipant\StoreEventParticipantRequest;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use App\Services\EventRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventParticipantController extends Controller
{
    public function __construct(private readonly EventRegistrationService $registrationService) {}

    public function candidates(Request $request, Event $event): JsonResponse
    {
        abort_unless(in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED], true), 403);

        $filters = $request->validate([
            'type' => ['required', Rule::in(EventRegistration::types())],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $isEmployee = $filters['type'] === EventRegistration::TYPE_EMPLOYEE;
        $table = $isEmployee ? 'employees' : 'external_participants';
        $foreignKey = $isEmployee ? 'employee_id' : 'external_participant_id';
        $identifier = $isEmployee ? 'nip' : 'phone';
        $query = $isEmployee ? Employee::query()->with('department:id,name') : ExternalParticipant::query();
        $query->where("{$table}.is_active", true)
            ->leftJoin('event_registrations as registration', fn (JoinClause $join): JoinClause => $join
                ->on("registration.{$foreignKey}", '=', "{$table}.id")
                ->where('registration.event_id', $event->id)
                ->where('registration.registration_type', $filters['type']));

        if ($isEmployee && isset($filters['department_id'])) {
            $query->where('employees.department_id', $filters['department_id']);
        }
        if (isset($filters['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn (Builder $search): Builder => $search->whereRaw("{$table}.name like ? escape '!'", [$pattern])
                ->orWhereRaw("{$table}.{$identifier} like ? escape '!'", [$pattern]));
        }

        $limit = EventRegistrationService::MAX_BULK_PARTICIPANTS;
        $selectableIds = (clone $query)
            ->where(fn (Builder $eligible): Builder => $eligible->whereNull('registration.id')
                ->orWhere('registration.status', EventRegistration::STATUS_CANCELLED))
            ->limit($limit + 1)->pluck("{$table}.id");

        $page = $query->orderBy("{$table}.name")->orderBy("{$table}.id")
            ->paginate(15, ["{$table}.id", "{$table}.name", "{$table}.{$identifier}",
                $isEmployee ? 'employees.department_id' : 'external_participants.organization', 'registration.status as registration_status']);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Employee|ExternalParticipant $participant): array => [
                'id' => $participant->id,
                'name' => $participant->name,
                'identifier' => $participant->{$identifier},
                'affiliation' => $isEmployee ? $participant->department?->name : $participant->organization,
                'status' => $participant->registration_status,
            ]),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'selectable_ids' => $selectableIds->count() > $limit ? null : $selectableIds,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Register employees or external participants.
     */
    public function store(StoreEventParticipantRequest $request, Event $event): RedirectResponse
    {
        $data = $request->validated();
        $dayIds = match ($data['day_selection'] ?? null) {
            'all' => null,
            'selected' => $data['day_ids'],
            default => [],
        };

        try {
            $groups = $data['participant_groups'] ?? [$data['participant_type'] => $data['participant_ids']];
            $result = $this->registrationService->registerGroups($event, $groups, $dayIds);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('events.show', $event).'#event-participants');
        }

        $message = sprintf('Registrasi selesai. %d peserta baru, %d diaktifkan kembali, %d sudah terdaftar.', $result['created'], $result['reactivated'], $result['skipped']);

        return redirect()
            ->to(route('events.show', $event).'#event-participants')
            ->with('success', $message);
    }

    /**
     * Cancel participant registration.
     */
    public function destroy(Event $event, EventRegistration $registration): RedirectResponse
    {
        Gate::authorize('events.update');

        /*
        |--------------------------------------------------------------------------
        | Validate Registration Ownership
        |--------------------------------------------------------------------------
        */

        abort_unless($registration->event_id === $event->id, 404);

        /*
        |--------------------------------------------------------------------------
        | Cancel Registration
        |--------------------------------------------------------------------------
        */

        $this->registrationService->cancel($registration);

        return redirect()
            ->to(route('events.show', $event).'#event-participants')
            ->with('success', 'Registrasi peserta berhasil dibatalkan.');
    }

    /**
     * Reactivate a cancelled event registration.
     */
    public function reactivate(Event $event, EventRegistration $registration): RedirectResponse
    {
        Gate::authorize('events.update');

        /*
    |--------------------------------------------------------------------------
    | Validate Registration Ownership
    |--------------------------------------------------------------------------
    */

        abort_unless($registration->event_id === $event->id, 404);

        /*
    |--------------------------------------------------------------------------
    | Reactivate Registration
    |--------------------------------------------------------------------------
    */

        try {
            $this->registrationService->reactivate($registration);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('events.show', $event).'#event-participants');
        }

        return redirect()
            ->to(route('events.show', $event).'#event-participants')
            ->with('success', 'Registrasi peserta berhasil diaktifkan kembali.');
    }
}
