@php
    $isEdit = isset($role);

    $selectedPermissions = old('permissions', $isEdit ? $role->permissions->pluck('name')->toArray() : []);
@endphp

<div class="row row-cards">

    {{-- Role Information --}}
    <div class="col-12">

        <x-ui.card title="Informasi Role" subtitle="Masukkan informasi dasar role pengguna">

            <x-ui.form.input name="name" label="Nama Role" :value="$role->name ?? ''" placeholder="Contoh: event-admin"
                required />

            <div class="form-hint">
                Gunakan huruf kecil, angka, dan tanda hubung (-).
                Contoh: event-admin, panitia, monitoring.
            </div>

        </x-ui.card>

    </div>

    {{-- Permission Management --}}
    <div class="col-12">

        <x-ui.card title="Permission Management" subtitle="Tentukan hak akses yang diberikan kepada role">

            <div class="d-flex justify-content-between align-items-center mb-3">

                <div>
                    <h4 class="mb-1">
                        Available Permissions
                    </h4>

                    <div class="text-secondary small">
                        Pilih permission berdasarkan kebutuhan role.
                    </div>
                </div>

                <div class="btn-list">

                    <x-ui.button type="button" variant="secondary" size="sm" id="selectAllPermissions">
                        Select All
                    </x-ui.button>

                    <x-ui.button type="button" variant="secondary" size="sm" :outline="true"
                        id="clearAllPermissions">
                        Clear All
                    </x-ui.button>

                </div>

            </div>

            <div class="row g-3">

                @foreach ($permissions as $module => $items)
                    <div class="col-md-6">

                        <div class="card h-100">

                            <div class="card-header">

                                <div class="d-flex align-items-center justify-content-between w-100">

                                    <h3 class="card-title text-capitalize">
                                        {{ str_replace('-', ' ', $module) }}
                                    </h3>

                                    <label class="form-check mb-0">

                                        <input type="checkbox" class="form-check-input module-select-all"
                                            data-module="{{ $module }}">

                                        <span class="form-check-label">
                                            Select All
                                        </span>

                                    </label>

                                </div>

                            </div>

                            <div class="card-body">

                                @foreach ($items as $permission)
                                    <label class="form-check mb-3">

                                        <input type="checkbox" class="form-check-input permission-checkbox"
                                            name="permissions[]" value="{{ $permission->name }}"
                                            data-module="{{ $module }}" @checked(in_array($permission->name, $selectedPermissions))>

                                        <span class="form-check-label">
                                            {{ $permission->name }}
                                        </span>

                                    </label>
                                @endforeach

                            </div>

                        </div>

                    </div>
                @endforeach

            </div>

            @error('permissions')
                <div class="text-danger mt-3">
                    {{ $message }}
                </div>
            @enderror

            @error('permissions.*')
                <div class="text-danger mt-3">
                    {{ $message }}
                </div>
            @enderror

        </x-ui.card>

    </div>

    {{-- Actions --}}
    <div class="col-12">

        <div class="d-flex justify-content-end gap-2">

            <x-ui.button variant="secondary" href="{{ route('settings.roles.index') }}">
                Batal
            </x-ui.button>

            <x-ui.button type="submit">
                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Role' }}
            </x-ui.button>

        </div>

    </div>

</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {

                const permissionCheckboxes = document.querySelectorAll(
                    '.permission-checkbox'
                );

                const moduleCheckboxes = document.querySelectorAll(
                    '.module-select-all'
                );

                function updateModuleCheckboxes() {

                    moduleCheckboxes.forEach(moduleCheckbox => {

                        const module = moduleCheckbox.dataset.module;

                        const permissions = document.querySelectorAll(
                            `.permission-checkbox[data-module="${module}"]`
                        );

                        const checked = Array.from(permissions)
                            .filter(checkbox => checkbox.checked);

                        moduleCheckbox.checked =
                            checked.length === permissions.length;

                        moduleCheckbox.indeterminate =
                            checked.length > 0 &&
                            checked.length < permissions.length;

                    });

                }

                // Select all permissions
                document.getElementById('selectAllPermissions')
                    ?.addEventListener('click', function() {

                        permissionCheckboxes.forEach(checkbox => {
                            checkbox.checked = true;
                        });

                        updateModuleCheckboxes();

                    });

                // Clear all permissions
                document.getElementById('clearAllPermissions')
                    ?.addEventListener('click', function() {

                        permissionCheckboxes.forEach(checkbox => {
                            checkbox.checked = false;
                        });

                        updateModuleCheckboxes();

                    });

                // Select all permissions within a module
                moduleCheckboxes.forEach(moduleCheckbox => {

                    moduleCheckbox.addEventListener('change', function() {

                        const module = this.dataset.module;

                        document.querySelectorAll(
                            `.permission-checkbox[data-module="${module}"]`
                        ).forEach(checkbox => {
                            checkbox.checked = this.checked;
                        });

                        updateModuleCheckboxes();

                    });

                });

                // Update module state when individual permission changes
                permissionCheckboxes.forEach(checkbox => {

                    checkbox.addEventListener('change', function() {
                        updateModuleCheckboxes();
                    });

                });

                updateModuleCheckboxes();

            });
        </script>
    @endpush
@endonce
