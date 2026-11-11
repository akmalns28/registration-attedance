@extends('layouts.app')

@section('title', 'Employee Management')

@section('content')
    <div class="container-xl">

        {{-- Page Header --}}
        <div class="page-header d-print-none">
            <div class="row align-items-center g-3">
                <div class="col">
                    <div class="page-pretitle">
                        Master Data
                    </div>

                    <h2 class="page-title">
                        Employee Management
                    </h2>

                    <div class="text-secondary mt-1">
                        Kelola data karyawan internal perusahaan.
                    </div>
                </div>

                @can('employees.manage')
                    <div class="col-auto ms-auto">
                        <a href="{{ route('employees.create') }}" class="btn btn-primary">
                            <i class="ti ti-plus me-2"></i>
                            Tambah Karyawan
                        </a>
                    </div>
                @endcan
            </div>
        </div>

        {{-- Summary --}}
        <div class="row row-cards mt-3">

            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">
                            Total Karyawan
                        </div>

                        <div class="h1 mb-0">
                            {{ $employees->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">
                            Karyawan Aktif
                        </div>

                        <div class="h1 text-success mb-0">
                            {{ $employees->where('is_active', true)->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">
                            Karyawan Nonaktif
                        </div>

                        <div class="h1 text-danger mb-0">
                            {{ $employees->where('is_active', false)->count() }}
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- Employee Table --}}
        <div class="row row-cards mt-3">
            <div class="col-12">
                <div class="card">

                    <div class="card-header">
                        <h3 class="card-title">
                            Daftar Karyawan
                        </h3>

                        <div class="card-actions">
                            <span class="badge bg-primary-lt">
                                {{ $employees->count() }} Karyawan
                            </span>
                        </div>
                    </div>

                    <div class="card-body">

                        <div class="table-responsive">
                            <table id="employeesTable" class="table table-vcenter table-hover w-100" data-datatable
                                data-export="true" data-filename="employee-management">
                                <thead>
                                    <tr>
                                        <th class="no-export">No.</th>
                                        <th>NIK</th>
                                        <th>Nama Karyawan</th>
                                        <th>Departemen</th>
                                        <th>Email</th>
                                        <th>No. HP</th>
                                        <th>Status</th>

                                        @can('employees.manage')
                                            <th class="no-sort no-export text-end">
                                                Action
                                            </th>
                                        @endcan
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($employees as $employee)
                                        <tr>
                                            <td>
                                                {{ $loop->iteration }}
                                            </td>

                                            <td>
                                                <span class="badge bg-azure-lt">
                                                    {{ $employee->nik }}
                                                </span>
                                            </td>

                                            <td>
                                                <span class="fw-semibold">
                                                    {{ $employee->name }}
                                                </span>
                                            </td>

                                            <td>
                                                {{ $employee->department?->name ?? '-' }}
                                            </td>

                                            <td>
                                                {{ $employee->email ?? '-' }}
                                            </td>

                                            <td>
                                                {{ $employee->phone ?? '-' }}
                                            </td>

                                            <td>
                                                @if ($employee->is_active)
                                                    <span class="badge bg-success-lt">
                                                        Active
                                                    </span>
                                                @else
                                                    <span class="badge bg-danger-lt">
                                                        Inactive
                                                    </span>
                                                @endif
                                            </td>

                                            @can('employees.manage')
                                                <td class="text-end">
                                                    <div class="btn-list justify-content-end">

                                                        <a href="{{ route('employees.edit', $employee) }}"
                                                            class="btn btn-sm btn-outline-primary">
                                                            <i class="ti ti-edit me-1"></i>
                                                            Edit
                                                        </a>

                                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                            data-bs-toggle="modal" data-bs-target="#deleteEmployeeModal"
                                                            data-delete-url="{{ route('employees.destroy', $employee) }}"
                                                            data-delete-name="{{ $employee->name }}">
                                                            <i class="ti ti-trash me-1"></i>
                                                            Hapus
                                                        </button>

                                                    </div>
                                                </td>
                                            @endcan
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

    {{-- Delete Confirmation Modal --}}
    @can('employees.manage')
        <div class="modal modal-blur fade" id="deleteEmployeeModal" tabindex="-1" aria-labelledby="deleteEmployeeModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-status bg-danger"></div>

                    <div class="modal-body text-center py-4">

                        <i class="ti ti-alert-triangle text-danger fs-1"></i>

                        <h3 id="deleteEmployeeModalLabel" class="mt-3">
                            Hapus Karyawan?
                        </h3>

                        <p class="text-secondary">
                            Apakah Anda yakin ingin menghapus
                            <strong id="deleteEmployeeName"></strong>?
                        </p>

                        <div class="alert alert-warning text-start mb-0">
                            Data karyawan yang sudah memiliki histori
                            event sebaiknya dinonaktifkan, bukan dihapus.
                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Batal
                        </button>

                        <form id="deleteEmployeeForm" method="POST" data-form data-loading-text="Menghapus...">
                            @csrf
                            @method('DELETE')

                            <button type="submit" class="btn btn-danger">
                                Ya, Hapus
                            </button>
                        </form>

                    </div>

                </div>
            </div>
        </div>

        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const modal = document.getElementById('deleteEmployeeModal');

                    if (!modal) return;

                    modal.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;

                        if (!button) return;

                        const form = document.getElementById('deleteEmployeeForm');
                        const name = document.getElementById('deleteEmployeeName');

                        form.action = button.dataset.deleteUrl;
                        name.textContent = button.dataset.deleteName;
                    });
                });
            </script>
        @endpush
    @endcan

@endsection
