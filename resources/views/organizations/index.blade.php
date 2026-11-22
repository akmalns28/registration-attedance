@extends('layouts.app')
@section('title', 'Organization Management')

@section('content')
    @php
        $canEdit = auth()->user()->can('departments.update');
        $details = [];
        $alphabetical = SORT_NATURAL | SORT_FLAG_CASE;
    @endphp

    <x-ui.page-header title="Organization Management">
        <x-slot:actions>
            @can('departments.create')
                <x-ui.button :href="route('organizations.create')">
                    <x-ui.icon name="plus" class="me-2" />Tambah Organisasi
                </x-ui.button>
            @endcan
            @canany(['departments.create', 'departments.update'])
                <x-ui.button :href="route('organizations.import.index')" outline>Import Organization</x-ui.button>
            @endcanany
        </x-slot:actions>
    </x-ui.page-header>

    @if ($search !== '')
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span>Hasil untuk <strong>{{ $search }}</strong></span>
            <x-ui.button :href="route('organizations.index')" variant="secondary" size="sm" outline>Reset</x-ui.button>
        </div>
    @endif

    <div data-organization-index>
        <x-ui.card>
            <div class="table-responsive">
                <table id="organizationsTable" class="table table-vcenter table-hover w-100" data-datatable
                    data-export="{{ auth()->user()->can('departments.export') ? 'true' : 'false' }}"
                    data-filename="organization-management">
                    <caption class="visually-hidden">Daftar entitas dan struktur organisasi</caption>
                    <thead>
                        <tr>
                            <th scope="col" data-priority="4">Kode Entitas</th>
                            <th scope="col" data-priority="1">Nama Entitas</th>
                            <th scope="col">Departemen</th>
                            <th scope="col">Divisi</th>
                            <th scope="col" data-priority="5" class="text-end">Total Karyawan</th>
                            <th scope="col" data-priority="3">Status</th>
                            <th scope="col" data-priority="2" class="no-sort no-export text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entities as $entity)
                            @php
                                $recordKey = 'entity-'.$entity->id;
                                $departments = $entity->departments->sortBy('name', $alphabetical)->map(fn ($department) => [
                                    'label' => $department->organizationLabel(), 'active' => $department->is_active,
                                ]);
                                $divisions = $entity->departments->flatMap(fn ($department) => $department->divisions
                                    ->map(fn ($division) => [
                                        'name' => $division->name, 'label' => $division->organizationLabel(),
                                        'department' => $department->organizationLabel(),
                                        'active' => $division->is_active,
                                    ]))->sortBy('name', $alphabetical);
                                $details[$recordKey] = [
                                    'label' => $entity->organizationLabel(),
                                    'active' => $entity->is_active,
                                    'employees' => $entity->employees_count,
                                    'departments' => $departments->values(),
                                    'divisions' => $divisions->values(),
                                ];
                            @endphp
                            <tr data-organization-entity="{{ $entity->id }}">
                                <td><span class="font-monospace">{{ $entity->code ?? '—' }}</span></td>
                                <td><span class="d-inline-block text-truncate align-middle fw-semibold" style="max-width: 12rem" title="{{ $entity->name }}">{{ $entity->name }}</span></td>
                                @include('organizations._related_badges', [
                                    'items' => $departments, 'type' => 'departments', 'label' => 'departemen', 'total' => $entity->departments_count,
                                ])
                                @include('organizations._related_badges', [
                                    'items' => $divisions, 'type' => 'divisions', 'label' => 'divisi', 'total' => $entity->divisions_count,
                                ])
                                <td class="text-end">{{ $entity->employees_count }}</td>
                                <td data-order="{{ (int) $entity->is_active }}">@include('organizations._status', ['record' => $entity])</td>
                                <td>
                                    <div class="btn-list flex-nowrap justify-content-end">
                                        <x-ui.action icon="eye" label="Lihat Organisasi" aria-controls="organization-related-modal"
                                            aria-haspopup="dialog" :data-organization-record="$recordKey" data-organization-list="all" />
                                        @if ($canEdit)
                                            <x-ui.action icon="pencil" label="Edit Organisasi" variant="primary" :href="route('organizations.edit', $entity)" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        @if ($legacyDepartments->isNotEmpty())
            <x-ui.card title="Departemen tanpa entitas" class="mt-3">
                <ul class="list-group list-group-flush">
                    @foreach ($legacyDepartments as $department)
                        @php
                            $recordKey = 'department-'.$department->id;
                            $details[$recordKey] = [
                                'label' => $department->organizationLabel(),
                                'active' => $department->is_active,
                                'employees' => $department->employees_count,
                                'divisions' => $department->divisions->sortBy('name', $alphabetical)->values()
                                    ->map(fn ($division) => ['label' => $division->organizationLabel(), 'active' => $division->is_active]),
                            ];
                        @endphp
                        <li class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                            <span class="fw-medium text-break">{{ $department->organizationLabel() }}</span>
                            @include('organizations._status', ['record' => $department])
                            <div class="btn-list flex-nowrap ms-auto">
                                <x-ui.action icon="eye" label="Lihat Departemen" aria-controls="organization-related-modal"
                                    aria-haspopup="dialog" :data-organization-record="$recordKey" data-organization-list="all" />
                                @if ($canEdit)
                                    <x-ui.action icon="pencil" label="Edit Departemen" variant="primary" :href="route('departments.edit', $department)" />
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3">{{ $legacyDepartments->links() }}</div>
            </x-ui.card>
        @endif

        @include('organizations._related_modal')
        <script type="application/json" data-organization-details>@json($details)</script>
    </div>
@endsection
