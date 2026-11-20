@can('events.update')

    @if (in_array($event->status, ['draft', 'published']))
        <div class="modal modal-blur fade" id="addExternalModal" tabindex="-1" aria-labelledby="addExternalModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">

                <div class="modal-content">

                    <div class="modal-header">

                        <h3 class="modal-title" id="addExternalModalLabel">
                            <x-ui.icon name="users-plus" class="me-2" />
                            Add External Participants
                        </h3>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

                    </div>

                    <form method="POST" action="{{ route('events.participants.store', $event) }}" data-form
                        data-loading-text="Mendaftarkan peserta...">
                        @csrf

                        <input type="hidden" name="participant_type" value="external">

                        <div class="modal-body">

                            <div class="alert alert-info">

                                <x-ui.icon name="info-circle" class="me-2" />

                                Pilih External Participant yang sudah
                                tersedia pada Master Data.

                            </div>

                            <div class="mb-3">

                                <label for="external_ids" class="form-label required">
                                    Pilih External Participant
                                </label>

                                <select id="external_ids" name="participant_ids[]"
                                    class="form-select @error('participant_ids') is-invalid @enderror" multiple
                                    data-tom-select data-placeholder="Cari nama atau organisasi..." required>

                                    @foreach ($externalParticipants as $participant)
                                        <option value="{{ $participant->id }}" @selected(old('participant_type') === 'external' && in_array((string) $participant->id, array_map('strval', (array) old('participant_ids', [])), true))>
                                            {{ $participant->name }}

                                            @if ($participant->organization)
                                                — {{ $participant->organization }}
                                            @endif
                                        </option>
                                    @endforeach

                                </select>

                                @error('participant_ids')
                                    <div class="invalid-feedback d-block">
                                        {{ $message }}
                                    </div>
                                @enderror

                                @error('participants')
                                    <div class="text-danger small mt-2">
                                        {{ $message }}
                                    </div>
                                @enderror

                                <div class="form-hint mt-2">
                                    Peserta yang belum tersedia harus
                                    ditambahkan ke Master External Participant
                                    terlebih dahulu.
                                </div>

                            </div>

                            @include('events.partials.invitation-day-picker', ['pickerId' => 'external-invitation'])

                            <div class="d-flex justify-content-between align-items-center">

                                <div class="text-secondary small">
                                    Total External Registered:
                                    <strong>
                                        {{ $event->registered_externals_count }}
                                    </strong>
                                </div>

                                @can('external-participants.manage')
                                    <a href="{{ route('external-participants.create') }}"
                                        class="btn btn-outline-secondary btn-sm">
                                        <x-ui.icon name="plus" class="me-1" />
                                        New External Participant
                                    </a>
                                @endcan

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                Batal
                            </button>

                            <button type="submit" class="btn btn-primary">
                                <x-ui.icon name="user-plus" class="me-2" />
                                Register Participants
                            </button>

                        </div>

                    </form>

                </div>

            </div>
        </div>
    @endif
@endcan
