@can('events.update')

    @if (in_array($event->status, ['draft', 'published']))
        <div class="modal modal-blur fade" id="addEmployeeModal" tabindex="-1" aria-labelledby="addEmployeeModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">

                <div class="modal-content">

                    <div class="modal-header">

                        <h3 class="modal-title" id="addEmployeeModalLabel">
                            <x-ui.icon name="user-plus" class="me-2" />
                            Add Employee Participants
                        </h3>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

                    </div>

                    <form method="POST" action="{{ route('events.participants.store', $event) }}" data-form
                        data-loading-text="Mendaftarkan peserta...">
                        @csrf

                        <input type="hidden" name="participant_type" value="employee">

                        <div class="modal-body">

                            <div class="alert alert-info">

                                <x-ui.icon name="info-circle" class="me-2" />

                                Pilih satu atau beberapa Employee
                                untuk didaftarkan ke event.

                            </div>

                            <div class="mb-3">

                                <label for="employee_ids" class="form-label required">
                                    Pilih Employee
                                </label>

                                <div class="employee-select" data-tom-select-dropdown-parent>
                                    <select id="employee_ids" name="participant_ids[]"
                                        class="form-select @error('participant_ids') is-invalid @enderror" multiple
                                        data-tom-select data-placeholder="Cari nama atau NIP Employee..." required>

                                        @foreach ($employees as $employee)
                                            <option value="{{ $employee->id }}" @selected(old('participant_type') === 'employee' && in_array((string) $employee->id, array_map('strval', (array) old('participant_ids', [])), true))>
                                                {{ $employee->name }}
                                                — {{ $employee->nip }}
                                            </option>
                                        @endforeach

                                    </select>
                                </div>

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
                                    Gunakan kolom pencarian untuk memilih
                                    beberapa Employee sekaligus.
                                </div>

                            </div>

                            <div class="row g-3">

                                <div class="col-md-6">

                                    <div class="card bg-primary-lt">

                                        <div class="card-body">

                                            <div class="subheader">
                                                Registered Employees
                                            </div>

                                            <div class="h2 mb-0">
                                                {{ $event->registered_employees_count }}
                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="card bg-secondary-lt">

                                        <div class="card-body">

                                            <div class="subheader">
                                                Remaining Capacity
                                            </div>

                                            <div class="h2 mb-0">
                                                {{ $remainingCapacity ?? 'Unlimited' }}
                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                Batal
                            </button>

                            <button type="submit" class="btn btn-primary">
                                <x-ui.icon name="user-plus" class="me-2" />
                                Register Employees
                            </button>

                        </div>

                    </form>

                </div>

            </div>
        </div>
    @endif
@endcan
