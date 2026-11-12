@php
    $isEdit = isset($participant);

    $isActive = old('is_active', $participant->is_active ?? true);
@endphp

<div class="row row-cards">

    {{-- Participant Information --}}
    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Informasi Peserta
                </h3>
            </div>

            <div class="card-body">

                {{-- Name --}}
                <div class="mb-3">
                    <label for="name" class="form-label required">
                        Nama Peserta
                    </label>

                    <input type="text" id="name" name="name"
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $participant->name ?? '') }}" placeholder="Masukkan nama lengkap peserta"
                        maxlength="150" required>

                    @error('name')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- Organization --}}
                <div class="mb-3">
                    <label for="organization" class="form-label">
                        Organisasi / Perusahaan
                    </label>

                    <input type="text" id="organization" name="organization"
                        class="form-control @error('organization') is-invalid @enderror"
                        value="{{ old('organization', $participant->organization ?? '') }}"
                        placeholder="Contoh: PT Contoh Indonesia" maxlength="150">

                    @error('organization')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- Position --}}
                <div class="mb-3">
                    <label for="position" class="form-label">
                        Jabatan
                    </label>

                    <input type="text" id="position" name="position"
                        class="form-control @error('position') is-invalid @enderror"
                        value="{{ old('position', $participant->position ?? '') }}"
                        placeholder="Contoh: Manager, Staff, Vendor" maxlength="150">

                    @error('position')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- Email --}}
                <div class="mb-3">
                    <label for="email" class="form-label">
                        Email
                    </label>

                    <input type="email" id="email" name="email"
                        class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email', $participant->email ?? '') }}" placeholder="nama@perusahaan.com"
                        maxlength="255">

                    @error('email')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Email bersifat opsional dan dapat digunakan
                        bersama oleh peserta dari organisasi yang sama.
                    </div>
                </div>

                {{-- Phone --}}
                <div class="mb-3">
                    <label for="phone" class="form-label">
                        No. HP
                    </label>

                    <input type="tel" id="phone" name="phone"
                        class="form-control @error('phone') is-invalid @enderror"
                        value="{{ old('phone', $participant->phone ?? '') }}" placeholder="Contoh: 081234567890"
                        maxlength="20">

                    @error('phone')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Nomor HP dapat digunakan untuk pengiriman
                        informasi event melalui WhatsApp.
                    </div>
                </div>

            </div>
        </div>

    </div>

    {{-- Participant Status --}}
    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Status Peserta
                </h3>
            </div>

            <div class="card-body">

                <input type="hidden" name="is_active" value="0">

                <label class="form-check form-switch">

                    <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                        @checked((bool) $isActive)>

                    <span class="form-check-label">
                        Peserta Aktif
                    </span>

                </label>

                <div class="text-secondary small mt-3">
                    Peserta aktif dapat dipilih dalam
                    proses registrasi event.
                </div>

                <div class="alert alert-info mt-3 mb-0">
                    <i class="ti ti-info-circle me-1"></i>

                    Peserta nonaktif tetap tersimpan
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

            <a href="{{ route('external-participants.index') }}" class="btn btn-outline-secondary">
                Batal
            </a>

            <button type="submit" class="btn btn-primary">
                <i class="ti ti-device-floppy me-2"></i>

                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Peserta' }}
            </button>

        </div>

    </div>

</div>
