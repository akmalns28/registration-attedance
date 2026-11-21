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

                <div class="row g-2 mb-3">
                    <div class="col-sm-3">
                        <label for="candidate-type" class="form-label">Participant Type</label>
                        <select id="candidate-type" class="form-select">
                            <option value="employee">Employee</option>
                            <option value="external" @selected($previousType === 'external')>External</option>
                        </select>
                    </div>
                    <div class="col-sm-4" data-candidate-department-filter>
                        <label for="candidate-department" class="form-label">Department</label>
                        <select id="candidate-department" class="form-select">
                            <option value="">All Departments</option>
                            @foreach ($participantDepartments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm">
                        <label for="candidate-search" class="form-label">Cari peserta</label>
                        <input type="search" id="candidate-search" class="form-control" maxlength="100" placeholder="Nama atau NIP">
                    </div>
                </div>

                <div class="border rounded overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-vcenter table-hover card-table mb-0">
                            <thead>
                                <tr>
                                    <th class="w-1">
                                        <input type="checkbox" id="candidate-select-all" class="form-check-input m-0"
                                            aria-label="Select All (filtered results)" title="Select All (filtered results)" disabled>
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
                <div class="form-hint mb-3">Centang kotak di header untuk memilih semua peserta yang dapat didaftarkan dalam hasil filter.</div>
                <p data-candidate-feedback class="small text-danger mb-3" role="status" hidden></p>

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
