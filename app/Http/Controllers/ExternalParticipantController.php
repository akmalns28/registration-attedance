<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExternalParticipant\StoreExternalParticipantRequest;
use App\Http\Requests\ExternalParticipant\UpdateExternalParticipantRequest;
use App\Models\ExternalParticipant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ExternalParticipantController extends Controller
{
    /**
     * Display external participants.
     */
    public function index(): View
    {
        $participants = ExternalParticipant::query()->orderBy('name')->get();

        return view('external-participants.index', [
            'participants' => $participants,
        ]);
    }

    /**
     * Show participant creation form.
     */
    public function create(): View
    {
        return view('external-participants.create');
    }

    /**
     * Store external participant.
     */
    public function store(StoreExternalParticipantRequest $request): RedirectResponse
    {
        ExternalParticipant::create($request->validated());

        return redirect()->route('external-participants.index')->with('success', 'Peserta eksternal berhasil ditambahkan.');
    }

    /**
     * Show participant editing form.
     */
    public function edit(ExternalParticipant $externalParticipant): View
    {
        return view('external-participants.edit', [
            'participant' => $externalParticipant,
        ]);
    }

    /**
     * Update external participant.
     */
    public function update(UpdateExternalParticipantRequest $request, ExternalParticipant $externalParticipant): RedirectResponse
    {
        $externalParticipant->update($request->validated());

        return redirect()->route('external-participants.index')->with('success', 'Peserta eksternal berhasil diperbarui.');
    }

    /**
     * Delete external participant.
     */
    public function destroy(ExternalParticipant $externalParticipant): RedirectResponse
    {
        Gate::authorize('external-participants.manage');

        $externalParticipant->delete();

        return redirect()->route('external-participants.index')->with('success', 'Peserta eksternal berhasil dihapus.');
    }
}
