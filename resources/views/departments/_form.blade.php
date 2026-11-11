@php
    $isEdit = isset($department);

    $isActive = old('is_active', $isEdit ? $department->is_active : true);
@endphp

<div class="row row-cards">

    {{-- Department Information --}}
    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Informasi Departemen
                </h3>
            </div>

            <div class="card-body">

                {{-- Code --}}
                <div class="mb-3">
                    <label for="code" class="form-label required">
                        Kode Departemen
                    </label>

                    <input type="text" id="code" name="code"
                        class="form-control @error('code') is-invalid @enderror"
                        value="{{ old('code', $department->code ?? '') }}" placeholder="Contoh: IT, HRD, FIN"
                        maxlength="20" style="text-transform: uppercase" pattern="[A-Za-z0-9_-]+" required>

                    @error('code')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Maksimal 20 karakter. Gunakan huruf, angka,
                        underscore, atau tanda hubung.
                    </div>
                </div>

                {{-- Name --}}
                <div class="mb-3">
                    <label for="name" class="form-label required">
                        Nama Departemen
                    </label>

                    <input type="text" id="name" name="name"
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $department->name ?? '') }}" placeholder="Contoh: Information Technology"
                        maxlength="150" required>

                    @error('name')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="mb-3">
                    <label for="description" class="form-label">
                        Deskripsi
                    </label>

                    <textarea id="description" name="description" rows="4" maxlength="2000"
                        class="form-control @error('description') is-invalid @enderror"
                        placeholder="Masukkan deskripsi departemen (opsional)">{{ old('description', $department->description ?? '') }}</textarea>

                    @error('description')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Opsional, maksimal 2.000 karakter.
                    </div>
                </div>

            </div>
        </div>

    </div>

    {{-- Department Status --}}
    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Status Departemen
                </h3>
            </div>

            <div class="card-body">

                <input type="hidden" name="is_active" value="0">

                <label class="form-check form-switch">

                    <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                        @checked((bool) $isActive)>

                    <span class="form-check-label">
                        Aktifkan Departemen
                    </span>

                </label>

                <div class="text-secondary small mt-3">
                    Departemen aktif dapat digunakan dalam
                    pemilihan departemen pada master karyawan.
                </div>

                <div class="alert alert-info mt-3 mb-0">
                    <i class="ti ti-info-circle me-1"></i>

                    Departemen yang dinonaktifkan tetap
                    tersimpan untuk kebutuhan histori data.
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

            <a href="{{ route('departments.index') }}" class="btn btn-outline-secondary">
                Batal
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="ti ti-device-floppy me-2"></i>

                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Departemen' }}
            </button>

        </div>

    </div>

</div>
