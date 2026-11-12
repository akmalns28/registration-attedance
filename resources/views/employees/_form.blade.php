@php
    $isEdit = isset($employee);

    $selectedDepartment = old('department_id', $employee->department_id ?? '');

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
                        minlength="15" maxlength="15"
                        style="text-transform: uppercase" required>

                    @error('nip')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Nomor induk pegawai harus unik dan terdiri dari 15 karakter.
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

                {{-- Department --}}
                <div class="mb-3">
                    <label for="department_id" class="form-label required">
                        Departemen
                    </label>

                    <select id="department_id" name="department_id"
                        class="form-select @error('department_id') is-invalid @enderror" data-tom-select
                        data-placeholder="Pilih departemen..." required>
                        <option value="">
                            Pilih Departemen
                        </option>

                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) $selectedDepartment === (string) $department->id)>
                                {{ $department->name }}

                                @unless ($department->is_active)
                                    (Inactive)
                                @endunless
                            </option>
                        @endforeach
                    </select>

                    @error('department_id')
                        <div class="invalid-feedback d-block">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Hanya departemen aktif yang dapat dipilih.
                        Departemen lama yang nonaktif tetap ditampilkan
                        apabila sedang digunakan oleh karyawan ini.
                    </div>
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
                    Karyawan aktif dapat digunakan dalam
                    proses registrasi event internal.
                </div>

                <div class="alert alert-info mt-3 mb-0">
                    <i class="ti ti-info-circle me-1"></i>

                    Karyawan nonaktif tetap tersimpan
                    untuk kebutuhan histori registrasi
                    dan absensi event.
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
                <i class="ti ti-device-floppy me-2"></i>

                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Karyawan' }}
            </button>

        </div>

    </div>

</div>
