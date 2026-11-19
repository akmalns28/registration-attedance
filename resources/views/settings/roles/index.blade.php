@extends('layouts.app')

@section('title', 'Role Management')

@section('content')

    <x-ui.page-header title="Role Management" subtitle="Kelola role dan hak akses pengguna aplikasi">
        <x-slot:actions>
            <x-ui.button href="{{ route('settings.roles.create') }}">
                <x-ui.icon name="plus" class="me-2" />Tambah Role
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Daftar Role">

        @if ($roles->count())

            <div class="table-responsive">

                <table id="rolesTable" class="table table-vcenter table-hover" data-datatable data-export="{{ auth()->user()->can('reports.export') ? 'true' : 'false' }}"
                    data-filename="role-management">
                    <thead>
                        <tr>
                            <th width="60">No.</th>
                            <th class="all">Role</th>
                            <th>Permissions</th>
                            <th>Guard</th>
                            <th class="text-end no-export no-sort">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach ($roles as $role)
                            <tr>
                                <td class="text-secondary">
                                    {{ $loop->iteration }} 
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        {{ $role->name }}
                                    </div>

                                    @if ($role->name === 'super-admin')
                                        <x-ui.badge variant="warning">
                                            Protected
                                        </x-ui.badge>
                                    @endif
                                </td>

                                <td>
                                    <x-ui.badge variant="primary">
                                        {{ $role->permissions_count }}
                                        Permissions
                                    </x-ui.badge>
                                </td>

                                <td>
                                    <span class="text-secondary">
                                        {{ $role->guard_name }}
                                    </span>
                                </td>

                                <td class="text-end">

                                    @if ($role->name !== 'super-admin')
                                        <div class="btn-list justify-content-end">

                                            <x-ui.action icon="pencil" label="Edit Role" variant="primary" :href="route('settings.roles.edit', $role)" />

                                            <x-ui.action icon="trash" label="Hapus Role" variant="danger"
                                                data-bs-toggle="modal" data-bs-target="#deleteRoleModal{{ $role->id }}" />

                                        </div>
                                    @else
                                        <span class="text-secondary">
                                            Protected
                                        </span>
                                    @endif

                                </td>
                            </tr>
                        @endforeach

                    </tbody>

                </table>

            </div>

        @else
            <x-ui.empty-state title="Role Tidak Ditemukan"
                description="Belum ada role atau tidak ada role yang sesuai pencarian.">
                <x-slot:action>
                    <x-ui.button href="{{ route('settings.roles.create') }}">
                        Tambah Role
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>

        @endif

    </x-ui.card>

    {{-- Delete Confirmation Modals --}}
    @foreach ($roles as $role)
        @if ($role->name !== 'super-admin')
            <x-ui.modal :id="'deleteRoleModal' . $role->id" title="Konfirmasi Hapus Role" size="sm">
                <div class="text-center py-3">

                    <div class="mb-3">
                        <span class="text-danger fs-1">!</span>
                    </div>

                    <h3>Hapus Role?</h3>

                    <p class="text-secondary">
                        Apakah Anda yakin ingin menghapus role
                        <strong>{{ $role->name }}</strong>?
                    </p>

                    <p class="text-danger small">
                        Tindakan ini tidak dapat dibatalkan.
                    </p>

                </div>

                <x-slot:footer>

                    <form method="POST" action="{{ route('settings.roles.destroy', $role) }}" class="w-100">
                        @csrf
                        @method('DELETE')

                        <div class="d-flex gap-2">

                            <x-ui.button type="button" variant="secondary" class="w-50" data-bs-dismiss="modal">
                                Batal
                            </x-ui.button>

                            <x-ui.button type="submit" variant="danger" class="w-50">
                                Ya, Hapus
                            </x-ui.button>

                        </div>

                    </form>

                </x-slot:footer>

            </x-ui.modal>
        @endif
    @endforeach

@endsection
