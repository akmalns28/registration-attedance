@extends('layouts.app')

@section('title', 'User Management')

@section('content')

    <div class="container-xl">

        {{-- Page Header --}}
        <div class="page-header d-print-none">
            <div class="row align-items-center">

                <div class="col">
                    <div class="page-pretitle">
                        Settings
                    </div>

                    <h2 class="page-title">
                        User Management
                    </h2>

                    <div class="text-secondary mt-1">
                        Kelola akun, status akses, dan role pengguna.
                    </div>
                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('settings.users.create') }}" class="btn btn-primary">
                        <i class="ti ti-plus me-2"></i>
                        Tambah Pengguna
                    </a>

                </div>

            </div>
        </div>

        <div class="row row-cards mt-3">

            <div class="col-12">

                <div class="card">

                    <div class="card-header">
                        <h3 class="card-title">
                            Daftar Pengguna
                        </h3>
                    </div>

                    <div class="card-body">

                        <div class="table-responsive">

                            <table id="usersTable" class="table table-vcenter table-hover w-100" data-datatable
                                data-export="true" data-filename="user-management">

                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Pengguna</th>
                                        <th>Role</th>
                                        <th>SSO</th>
                                        <th>Status</th>
                                        <th class="no-export no-sort text-end">
                                            Action
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>

                                    @foreach ($users as $user)
                                        <tr>

                                            <td>
                                                {{ $loop->iteration }}
                                            </td>

                                            <td>
                                                <div class="d-flex align-items-center gap-3">

                                                    <span class="avatar avatar-sm bg-primary-lt">
                                                        {{ strtoupper(mb_substr($user->name, 0, 2)) }}
                                                    </span>

                                                    <div>

                                                        <div class="fw-semibold">
                                                            {{ $user->name }}
                                                        </div>

                                                        <div class="text-secondary small">
                                                            {{ $user->email }}
                                                        </div>

                                                    </div>

                                                </div>
                                            </td>

                                            <td>

                                                @forelse ($user->roles as $role)
                                                    <span class="badge bg-primary-lt me-1">
                                                        {{ $role->name }}
                                                    </span>

                                                @empty

                                                    <span class="text-secondary">
                                                        No Role
                                                    </span>
                                                @endforelse

                                            </td>

                                            <td>

                                                @if ($user->keycloak_id)
                                                    <span class="badge bg-success-lt">
                                                        Connected
                                                    </span>
                                                @else
                                                    <span class="badge bg-warning-lt">
                                                        Not Connected
                                                    </span>
                                                @endif

                                            </td>

                                            <td>

                                                @if ($user->is_active)
                                                    <span class="badge bg-success-lt">
                                                        Active
                                                    </span>
                                                @else
                                                    <span class="badge bg-danger-lt">
                                                        Inactive
                                                    </span>
                                                @endif

                                            </td>

                                            <td class="text-end">

                                                @if (auth()->id() !== $user->id && !$user->hasRole('super-admin'))
                                                    <a href="{{ route('settings.users.edit', $user) }}"
                                                        class="btn btn-sm btn-outline-primary">
                                                        <i class="ti ti-edit me-1"></i>
                                                        Edit
                                                    </a>
                                                @else
                                                    <span class="text-secondary small">
                                                        Protected
                                                    </span>
                                                @endif

                                            </td>

                                        </tr>
                                    @endforeach

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

@endsection
