@php
    $previousType = old('participant_type');
    $previousSelection = old('participant_groups', in_array($previousType, ['employee', 'external'], true)
        ? [$previousType => old('participant_ids', [])] : []);
@endphp
<div id="addParticipantsModal" class="modal modal-blur fade" tabindex="-1" aria-labelledby="addParticipantsModalLabel"
    aria-hidden="true" data-participant-picker data-candidates-url="{{ route('events.participants.candidates', $event, absolute: false) }}"
    data-selection-limit="{{ \App\Services\EventRegistrationService::MAX_BULK_PARTICIPANTS }}"
    @if ($participantErrors->isNotEmpty() && (session()->hasOldInput('participant_groups') || $previousType)) data-reopen @endif>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" method="POST" action="{{ route('events.participants.store', $event) }}" data-form
            data-loading-text="Mendaftarkan peserta...">
            @csrf
            <script type="application/json" data-previous-participants>@json($previousSelection)</script>
            <div data-selected-participant-inputs hidden></div>

            <div class="modal-header">
                <h3 id="addParticipantsModalLabel" class="modal-title">Add Participants</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <div class="modal-body">
                @if ($participantErrors->isNotEmpty())
                    <div class="alert alert-danger" role="alert">{{ $participantErrors->first() }}</div>
                @endif

                <x-ui.compact-filters id="participant-organization-filters" class="mb-3">
                    <x-slot:leading>
                        <div class="flex-shrink-0">
                            <label for="candidate-type" class="form-label">Jenis Peserta</label>
                            <select id="candidate-type" class="form-select">
                                <option value="employee">Employee</option>
                                <option value="external" @selected($previousType === 'external')>External</option>
                            </select>
                        </div>
                        <div class="participant-picker-search">
                            <label for="candidate-search" class="form-label">Cari peserta</label>
                            <input type="search" id="candidate-search" class="form-control" maxlength="100" placeholder="Nama atau NIP" autocomplete="off">
                        </div>
                    </x-slot:leading>
                    @include('organizations._filters', ['filterPrefix' => 'candidate'])
                </x-ui.compact-filters>

                <div class="border rounded overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter table-hover card-table mb-0">
                            <thead>
                                <tr>
                                    <th class="w-1">
                                        <input type="checkbox" id="candidate-select-all" class="form-check-input m-0"
                                            aria-label="Pilih semua hasil filter yang memenuhi syarat" aria-describedby="candidate-selection-scope" disabled>
                                    </th>
                                    <th>Nama</th>
                                    <th data-candidate-identifier>NIP</th>
                                    <th data-candidate-affiliation>Department</th>
                                    <th>Status registrasi</th>
                                </tr>
                            </thead>
                            <tbody data-candidate-rows></tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top p-2 px-sm-3">
                        <span data-candidate-results class="text-secondary small" role="status"></span>
                        <div class="btn-group" role="group" aria-label="Halaman peserta">
                            <button type="button" class="btn btn-sm" data-candidate-previous disabled>Sebelumnya</button>
                            <button type="button" class="btn btn-sm" data-candidate-next disabled>Berikutnya</button>
                        </div>
                    </div>
                </div>
                <div class="form-hint" id="candidate-selection-scope">Pilih semua mencakup peserta yang dapat didaftarkan dari semua halaman hasil filter.</div>
                <p data-candidate-feedback class="small text-danger mb-0 mt-2" role="status" hidden></p>
            </div>

            <div class="border-top px-3 pt-2 pb-1">
                @include('events.partials.invitation-day-picker', ['pickerId' => 'participant-invitation'])
            </div>

            <div class="modal-footer">
                <div class="d-flex flex-wrap align-items-center gap-2 me-auto">
                    <span class="badge bg-primary-lt" data-selected-count aria-live="polite">0 peserta dipilih</span>
                    <button type="button" class="btn btn-sm btn-ghost-secondary" data-clear-participants disabled>Hapus pilihan</button>
                    <span class="text-secondary small">Sisa kapasitas <strong>{{ $remainingCapacity ?? 'Unlimited' }}</strong></span>
                </div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary" disabled>Register Participants</button>
            </div>
        </form>
    </div>
</div>
