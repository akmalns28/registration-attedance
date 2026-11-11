@extends('layouts.app')

@section('title', 'Department Management')

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
                        Department Management
                    </h2>

                    <div class="text-secondary mt-1">
                        Kelola departemen yang digunakan untuk data karyawan.
                    </div>
                </div>

                @can('departments.create')
                    <div class="col-auto ms-auto">
                        <a href="{{ route('departments.create') }}" class="btn btn-primary">
                            <i class="ti ti-plus me-2"></i>
                            Tambah Departemen
                        </a>
                    </div>
                @endcan

            </div>
        </div>

        {{-- Department List --}}
        <div class="row row-cards mt-3">
            <div class="col-12">
                <div class="card">

                    <div class="card-header">
                        <h3 class="card-title">
                            Daftar Departemen
                        </h3>

                        <div class="card-actions">
                            <span class="badge bg-primary-lt">
                                {{ $departments->count() }} Departemen
                            </span>
                        </div>
                    </div>

                    <div class="card-body">

                        <div class="table-responsive">
                            <table id="departmentsTable" class="table table-vcenter table-hover w-100" data-datatable
                                data-export="{{ auth()->user()->can('departments.export') ? 'true' : 'false' }}"
                                data-filename="department-management">
                                <thead>
                                    <tr>
                                        <th class="no-sort no-export">
                                            No.
                                        </th>
                                        <th>Kode</th>
                                        <th>Nama Departemen</th>
                                        <th>Deskripsi</th>
                                        <th>Status</th>

                                        @canany(['departments.update', 'departments.delete'])
                                            <th class="no-sort no-export text-end">
                                                Action
                                            </th>
                                        @endcanany
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($departments as $department)
                                        <tr>
                                            <td>
                                                {{ $loop->iteration }}
                                            </td>

                                            <td>
                                                <span class="badge bg-azure-lt">
                                                    {{ $department->code }}
                                                </span>
                                            </td>

                                            <td>
                                                <span class="fw-semibold">
                                                    {{ $department->name }}
                                                </span>
                                            </td>

                                            <td>
                                                <span class="text-secondary">
                                                    {{ $department->description ?: '-' }}
                                                </span>
                                            </td>

                                            <td>
                                                @if ($department->is_active)
                                                    <span class="badge bg-success-lt">
                                                        <span class="badge-dot bg-success me-1"></span>
                                                        Active
                                                    </span>
                                                @else
                                                    <span class="badge bg-danger-lt">
                                                        <span class="badge-dot bg-danger me-1"></span>
                                                        Inactive
                                                    </span>
                                                @endif
                                            </td>

                                            @canany(['departments.update', 'departments.delete'])
                                                <td class="text-end">
                                                    <div class="btn-list justify-content-end">

                                                        @can('departments.update')
                                                            <a href="{{ route('departments.edit', $department) }}"
                                                                class="btn btn-sm btn-outline-primary">
                                                                <i class="ti ti-edit me-1"></i>
                                                                Edit
                                                            </a>
                                                        @endcan

                                                        @can('departments.delete')
                                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                                data-bs-toggle="modal" data-bs-target="#deleteDepartmentModal"
                                                                data-delete-url="{{ route('departments.destroy', $department) }}"
                                                                data-delete-name="{{ $department->name }}">
                                                                <i class="ti ti-trash me-1"></i>
                                                                Hapus
                                                            </button>
                                                        @endcan

                                                    </div>
                                                </td>
                                            @endcanany
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

    {{-- Reusable Delete Confirmation Modal --}}
    @can('departments.delete')
        <div class="modal modal-blur fade" id="deleteDepartmentModal" tabindex="-1"
            aria-labelledby="deleteDepartmentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-status bg-danger"></div>

                    <div class="modal-body text-center py-4">

                        <i class="ti ti-alert-triangle text-danger fs-1"></i>

                        <h3 class="mt-3" id="deleteDepartmentModalLabel">
                            Hapus Departemen?
                        </h3>

                        <p class="text-secondary">
                            Apakah Anda yakin ingin menghapus
                            <strong id="deleteDepartmentName"></strong>?
                        </p>

                        <div class="alert alert-warning text-start mb-0">
                            Departemen yang sudah digunakan oleh karyawan
                            tidak dapat dihapus. Gunakan status
                            <strong>Inactive</strong> jika diperlukan.
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Batal
                        </button>

                        <form id="deleteDepartmentForm" method="POST" data-form data-loading-text="Menghapus...">
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
                    const modal = document.getElementById('deleteDepartmentModal');

                    if (!modal) {
                        return;
                    }

                    modal.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;

                        if (!button) {
                            return;
                        }

                        const url = button.dataset.deleteUrl;
                        const name = button.dataset.deleteName;

                        const form = document.getElementById('deleteDepartmentForm');
                        const nameElement = document.getElementById('deleteDepartmentName');

                        form.action = url;
                        nameElement.textContent = name;
                    });
                });
            </script>
        @endpush
    @endcan

@endsection
