@extends('layouts.app')

@php
    $legacyDepartment ??= null;
    $title = $organization || $legacyDepartment ? 'Edit Organisasi' : 'Tambah Organisasi';
    $fields = ['id', 'code', 'name', 'is_active'];
    $departmentData = fn ($department) => [
        ...$department->only([...$fields, 'description']),
        'divisions' => $department->divisions->map(fn ($division) => $division->only($fields))->all(),
    ];
    $legacyData = ($legacyDepartments ?? collect())->mapWithKeys(fn ($department) => [$department->id => $departmentData($department)]);
    $departments = old('_organization_form') ? old('departments', []) : $organization?->departments->map($departmentData)->all();
    $departments = is_array($departments) ? array_filter($departments, 'is_array') : [];
    $root = $organization ?? $legacyDepartment;
    $entity = ['code' => old('code', $root?->code), 'name' => old('name', $root?->name),
        'is_active' => old('is_active', $root?->is_active ?? true)];
    $action = match (true) {
        $organization !== null => route('organizations.update', $organization),
        $legacyDepartment !== null => route('departments.update', $legacyDepartment),
        default => route('organizations.store'),
    };
@endphp

@section('title', $title)

@section('content')
    <x-ui.page-header :title="$title" />
    <form method="POST" action="{{ $action }}" data-form data-organization-form data-loading-text="Menyimpan organisasi...">
        @csrf
        @if ($root)
            @method('PUT')
        @endif
        <input type="hidden" name="_organization_form" value="1">
        @if ($errors->any())
            <x-ui.alert variant="danger" title="Periksa data organisasi" :dismissible="false" data-organization-validation>
                <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif
        <x-ui.card :title="$legacyDepartment ? 'Departemen lama' : 'Entitas'" class="mb-3" data-organization-root>
            @include('organizations._fields', ['record' => $entity, 'prefix' => '', 'path' => '', 'codeRequired' => !$root || $root->code !== null])
            @if ($legacyDepartment)
                <div class="mt-3">
                    <label for="description" class="form-label">Deskripsi</label>
                    <textarea id="description" name="description" @class(['form-control', 'is-invalid' => $errors->has('description')])
                        maxlength="2000" aria-describedby="description-error">{{ is_string($description = old('description', $legacyDepartment->description)) ? $description : '' }}</textarea>
                    <div id="description-error" class="invalid-feedback">{{ $errors->first('description') }}</div>
                </div>
            @endif
        </x-ui.card>

        @unless ($legacyDepartment)
            <x-ui.card title="Struktur Organisasi" class="mb-3">
                <p class="text-secondary" data-empty-departments @if ($departments !== []) hidden @endif>Belum ada departemen.</p>
                <div class="vstack gap-2" data-departments>
                    @foreach ($departments as $departmentIndex => $department)
                        @include('organizations._department', ['record' => $department, 'index' => $departmentIndex,
                            'legacy' => is_scalar($department['id'] ?? null) && $legacyData->has($department['id'])])
                    @endforeach
                </div>
                <div class="d-flex flex-wrap align-items-end gap-2 mt-3">
                    @can('departments.create')
                        <x-ui.button outline data-add-department><x-ui.icon name="plus" class="me-2" />Tambah Departemen</x-ui.button>
                    @endcan
                    @if ($legacyData->isNotEmpty())
                        <div>
                            <label for="legacy-department" class="form-label">Departemen lama tanpa entitas</label>
                            <select id="legacy-department" class="form-select" data-legacy-department data-records="{{ $legacyData->toJson() }}">
                                <option value="">Pilih departemen</option>
                                @foreach ($legacyDepartments as $department)
                                    <option value="{{ $department->id }}">{{ $department->organizationLabel() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-ui.button outline data-attach-department disabled>Masukkan ke Entitas</x-ui.button>
                    @endif
                </div>
            </x-ui.card>

            <template data-department-template>
                @include('organizations._department', ['record' => [], 'index' => '__department__', 'legacy' => false])
            </template>
            <template data-division-template>
                @include('organizations._division', ['record' => [], 'departmentIndex' => '__department__', 'index' => '__division__'])
            </template>
        @endunless
        <div data-organization-actions>
            <div class="d-flex flex-wrap justify-content-end gap-2">
                @can('departments.view')
                    <x-ui.button :href="route('organizations.index')" variant="secondary" outline>Batal</x-ui.button>
                @endcan
                <x-ui.button type="submit" data-organization-review-open><x-ui.icon name="device-floppy" class="me-2" />Simpan Organisasi</x-ui.button>
            </div>
        </div>
        <section class="card" data-organization-review aria-labelledby="organization-review-title" hidden>
            <div class="card-body">
                <h2 id="organization-review-title" class="h4" tabindex="-1">Konfirmasi simpan</h2>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <strong class="text-break" data-organization-review-name></strong>
                    <span class="badge" data-organization-review-status></span>
                </div>
                @unless ($legacyDepartment)
                    <p class="small text-secondary mb-0" data-organization-review-count></p>
                @endunless
                <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                    <x-ui.button variant="secondary" outline data-organization-review-back>Kembali Edit</x-ui.button>
                    <x-ui.button type="submit" data-organization-review-confirm>Konfirmasi Simpan</x-ui.button>
                </div>
            </div>
        </section>
    </form>
@endsection
