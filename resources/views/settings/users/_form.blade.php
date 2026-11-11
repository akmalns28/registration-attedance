@php
    $isEdit = isset($user);

    $selectedRoles = array_map('intval', old('roles', $isEdit ? $user->roles->pluck('id')->all() : []));

    $isActive = old('is_active', $isEdit ? $user->is_active : false);
@endphp

<div class="row row-cards">

    {{-- User Information --}}
    <div class="col-lg-8">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Informasi Pengguna
                </h3>
            </div>

            <div class="card-body">

                <div class="mb-3">

                    <label class="form-label required">
                        Nama Lengkap
                    </label>

                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $user->name ?? '') }}" placeholder="Masukkan nama lengkap" maxlength="255"
                        required>

                    @error('name')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                </div>

                <div class="mb-3">

                    <label class="form-label required">
                        Email Keycloak
                    </label>

                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email', $user->email ?? '') }}" placeholder="nama@perusahaan.com" maxlength="255"
                        @readonly($isEdit && filled($user->keycloak_id)) required>

                    @error('email')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="form-hint">
                        Gunakan alamat email yang sesuai dengan akun Keycloak.
                    </div>

                </div>

                <div class="alert alert-info mb-0" role="alert">

                    <div class="d-flex gap-2">

                        <i class="ti ti-shield-lock fs-2"></i>

                        <div>
                            <h4 class="alert-title">
                                Keycloak Single Sign-On
                            </h4>

                            <div class="text-secondary">
                                Aplikasi tidak menyimpan password pengguna.
                                Autentikasi dilakukan melalui Keycloak SSO.
                            </div>
                        </div>

                    </div>

                </div>

                @if ($isEdit)

                    <div class="mt-3">

                        <label class="form-label">
                            Status Koneksi SSO
                        </label>

                        @if ($user->keycloak_id)
                            <span class="badge bg-success-lt">
                                Connected
                            </span>
                        @else
                            <span class="badge bg-warning-lt">
                                Not Connected
                            </span>
                        @endif

                    </div>

                @endif

            </div>

        </div>

    </div>

    {{-- Authorization --}}
    <div class="col-lg-4">

        <div class="card">

            <div class="card-header">
                <h3 class="card-title">
                    Hak Akses
                </h3>
            </div>

            <div class="card-body">

                <div class="mb-4">

                    <label class="form-label required">
                        Role Pengguna
                    </label>

                    <div class="text-secondary small mb-3">
                        Pengguna dapat memiliki lebih dari satu role.
                    </div>

                    @forelse ($roles as $role)
                        <label class="form-check mb-2">

                            <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="form-check-input"
                                @checked(in_array($role->id, $selectedRoles, true))>

                            <span class="form-check-label">
                                {{ $role->name }}
                            </span>

                        </label>

                    @empty

                        <div class="alert alert-warning">
                            Belum tersedia role yang dapat diberikan.
                        </div>
                    @endforelse

                    @error('roles')
                        <div class="text-danger small mt-2">
                            {{ $message }}
                        </div>
                    @enderror

                    @error('roles.*')
                        <div class="text-danger small mt-2">
                            {{ $message }}
                        </div>
                    @enderror

                </div>

                <hr>

                {{-- Hidden field handles unchecked state --}}
                <input type="hidden" name="is_active" value="0">

                <div class="mb-2">

                    <label class="form-check form-switch">

                        <input type="checkbox" name="is_active" value="1" class="form-check-input"
                            @checked((bool) $isActive)>

                        <span class="form-check-label">
                            Aktifkan Akses
                        </span>

                    </label>

                </div>

                <div class="text-secondary small">
                    Jika dinonaktifkan, pengguna tidak dapat mengakses aplikasi.
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

            <a href="{{ route('settings.users.index') }}" class="btn btn-outline-secondary">
                Batal
            </a>

            <x-ui.button type="submit">
                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan User' }}
            </x-ui.button>

        </div>

    </div>

</div>
