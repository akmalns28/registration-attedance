@php
    $isEdit = isset($employee);

    $legacyAvailable = $departments->contains(fn ($department) => $department->entity_id === null);
    $defaultEntity = isset($employee) ? ($employee->department?->entity_id ?? 'legacy')
        : ($entities->isEmpty() && $legacyAvailable ? 'legacy' : '');
    $legacySelected = $departments->contains(fn ($department) => $department->entity_id === null
        && (string) $department->id === (string) (is_scalar(old('department_id')) ? old('department_id') : ''));
    $selected = [
        'entity' => old('entity_id', $defaultEntity) ?? ($legacySelected ? 'legacy' : ''),
        'department' => old('department_id', $employee->department_id ?? ''),
        'division' => old('division_id', $employee->division_id ?? ''),
    ];
    $isActive = old('is_active', $employee->is_active ?? true);
@endphp

<div class="row row-cards">

    {{-- Employee Information --}}
    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Informasi Karyawan
                </h3>
            </div>

            <div class="card-body">

                {{-- NIP --}}
                <div class="mb-3">
                    <label for="nip" class="form-label required">
                        NIP Karyawan
                    </label>

                    <input type="text" id="nip" name="nip"
                        class="form-control @error('nip') is-invalid @enderror"
                        value="{{ old('nip', $employee->nip ?? '') }}" placeholder="Contoh: EMP000000000001"
                        maxlength="255"
                        style="text-transform: uppercase" required>

                    @error('nip')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Nomor induk pegawai harus unik.
                    </div>
                </div>

                {{-- Employee Name --}}
                <div class="mb-3">
                    <label for="name" class="form-label required">
                        Nama Karyawan
                    </label>

                    <input type="text" id="name" name="name"
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $employee->name ?? '') }}" placeholder="Masukkan nama lengkap karyawan"
                        maxlength="150" required>

                    @error('name')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div data-employee-organization data-organization-selects>
                    <p class="small text-secondary" data-organization-loading role="status">Menyiapkan pilihan organisasi…</p>
                    @foreach (['entity' => 'Entitas', 'department' => 'Departemen', 'division' => 'Divisi'] as $level => $label)
                        @php
                            $field = $level.'_id';
                            $selectedId = is_scalar($selected[$level]) ? (string) $selected[$level] : '';
                            $options = match ($level) {
                                'entity' => $entities,
                                'department' => $departments,
                                'division' => $departments->flatMap->divisions,
                            };
                        @endphp
                        <div class="mb-3">
                            <label for="{{ $field }}" class="form-label {{ $level !== 'division' ? 'required' : '' }}">
                                {{ $label }}{{ $level === 'division' ? ' (opsional)' : '' }}
                            </label>
                            <select id="{{ $field }}" name="{{ $field }}" data-organization-select="{{ $level }}" data-tom-select
                                @class(['form-select', 'is-invalid' => $errors->has($field)])
                                data-placeholder="Pilih {{ strtolower($label) }}..." @required($level !== 'division')>
                                <option value="">{{ $level === 'division' ? 'Tanpa Divisi' : 'Pilih '.$label }}</option>
                                @if ($level === 'entity' && $legacyAvailable)
                                    <option value="legacy" @selected($selectedId === 'legacy')>Departemen lama (tanpa entitas)</option>
                                @endif
                                @foreach ($options as $option)
                                    <option value="{{ $option->id }}" @selected($selectedId === (string) $option->id)
                                        data-parent="{{ match ($level) {
                                            'department' => $option->entity_id ?? 'legacy',
                                            'division' => $option->department_id,
                                            default => '',
                                        } }}">
                                        {{ filled($option->code) ? '['.$option->code.'] ' : '' }}{{ $option->name }}{{ $option->is_active ? '' : ' (Tidak Aktif)' }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($level === 'entity' && $options->isEmpty() && !$legacyAvailable)
                                <p class="form-hint mb-0">Belum ada entitas aktif.</p>
                            @elseif ($level !== 'entity')
                                <p class="form-hint mb-0" data-organization-empty="{{ $level }}" role="status" hidden>
                                    {{ $level === 'department' ? 'Tidak ada departemen yang dapat dipilih.' : 'Tidak ada divisi yang dapat dipilih.' }}
                                </p>
                            @endif
                            @error($field)
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                </div>

                {{-- Email --}}
                <div class="mb-3">
                    <label for="email" class="form-label">
                        Email
                    </label>

                    <input type="email" id="email" name="email"
                        class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email', $employee->email ?? '') }}" placeholder="nama@perusahaan.com"
                        maxlength="255">

                    @error('email')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- Phone --}}
                <div class="mb-3">
                    <label for="phone" class="form-label">
                        No. HP
                    </label>

                    <input type="tel" id="phone" name="phone"
                        class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone', $employee->phone ?? '') }}" placeholder="Contoh: 081234567890"
                        maxlength="20">

                    @error('phone')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

            </div>
        </div>
    </div>

    {{-- Employee Status --}}
    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Status Karyawan
                </h3>
            </div>

            <div class="card-body">

                <input type="hidden" name="is_active" value="0">

                <label class="form-check form-switch">

                    <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                        @checked((bool) $isActive)>

                    <span class="form-check-label">
                        Karyawan Aktif
                    </span>

                </label>

                <div class="text-secondary small mt-3">
                    Hanya karyawan aktif yang dapat diregistrasikan. Menonaktifkan karyawan tetap menyimpan riwayat registrasi dan absensi.
                </div>

                @error('is_active')
                    <div class="text-danger small mt-2">
                        {{ $message }}
                    </div>
                @enderror

            </div>
        </div>
    </div>

    {{-- Form Actions --}}
    <div class="col-12">

        <div class="d-flex justify-content-end gap-2">

            <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">
                Batal
            </a>

            <button type="submit" class="btn btn-primary">
                <x-ui.icon name="device-floppy" class="me-2" />

                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Karyawan' }}
            </button>

        </div>

    </div>

</div>
