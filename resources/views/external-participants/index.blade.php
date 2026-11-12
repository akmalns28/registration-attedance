@extends('layouts.app')

@section('title', 'External Participant Management')

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
                        External Participant Management
                    </h2>

                    <div class="text-secondary mt-1">
                        Kelola data peserta eksternal, tamu undangan,
                        mitra bisnis, dan vendor.
                    </div>
                </div>

                @can('external-participants.manage')
                    <div class="col-auto ms-auto">
                        <a href="{{ route('external-participants.create') }}" class="btn btn-primary">
                            <i class="ti ti-plus me-2"></i>
                            Tambah Peserta
                        </a>
                    </div>
                @endcan

            </div>
        </div>

        {{-- Summary Cards --}}
        <div class="row row-cards mt-3">

            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">
                            Total Peserta
                        </div>

                        <div class="h1 mb-0">
                            {{ $participants->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">
                            Peserta Aktif
                        </div>

                        <div class="h1 text-success mb-0">
                            {{ $participants->where('is_active', true)->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">
                            Peserta Nonaktif
                        </div>

                        <div class="h1 text-danger mb-0">
                            {{ $participants->where('is_active', false)->count() }}
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- Participants Table --}}
        <div class="row row-cards mt-3">
            <div class="col-12">
                <div class="card">

                    <div class="card-header">
                        <h3 class="card-title">
                            Daftar Peserta Eksternal
                        </h3>

                        <div class="card-actions">
                            <span class="badge bg-primary-lt">
                                {{ $participants->count() }} Peserta
                            </span>
                        </div>
                    </div>

                    <div class="card-body">

                        <div class="table-responsive">
                            <table id="externalParticipantsTable" class="table table-vcenter table-hover w-100"
                                data-datatable data-export="true" data-filename="external-participants">
                                <thead>
                                    <tr>
                                        <th class="no-sort no-export">
                                            No.
                                        </th>

                                        <th>Nama Peserta</th>
                                        <th>Organisasi</th>
                                        <th>Jabatan</th>
                                        <th>Email</th>
                                        <th>No. HP</th>
                                        <th>Status</th>

                                        @can('external-participants.manage')
                                            <th class="no-sort no-export text-end">
                                                Action
                                            </th>
                                        @endcan
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($participants as $participant)
                                        <tr>
                                            <td>
                                                {{ $loop->iteration }}
                                            </td>

                                            <td>
                                                <span class="fw-semibold">
                                                    {{ $participant->name }}
                                                </span>
                                            </td>

                                            <td>
                                                {{ $participant->organization ?: '-' }}
                                            </td>

                                            <td>
                                                {{ $participant->position ?: '-' }}
                                            </td>

                                            <td>
                                                @if ($participant->email)
                                                    <a href="mailto:{{ $participant->email }}" class="text-reset">
                                                        {{ $participant->email }}
                                                    </a>
                                                @else
                                                    <span class="text-secondary">
                                                        -
                                                    </span>
                                                @endif
                                            </td>

                                            <td>
                                                {{ $participant->phone ?: '-' }}
                                            </td>

                                            <td>
                                                @if ($participant->is_active)
                                                    <span class="badge bg-success-lt">
                                                        Active
                                                    </span>
                                                @else
                                                    <span class="badge bg-danger-lt">
                                                        Inactive
                                                    </span>
                                                @endif
                                            </td>

                                            @can('external-participants.manage')
                                                <td class="text-end">
                                                    <div class="btn-list justify-content-end">

                                                        <a href="{{ route('external-participants.edit', $participant) }}"
                                                            class="btn btn-sm btn-outline-primary">
                                                            <i class="ti ti-edit me-1"></i>
                                                            Edit
                                                        </a>

                                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                            data-bs-toggle="modal" data-bs-target="#deleteParticipantModal"
                                                            data-delete-url="{{ route('external-participants.destroy', $participant) }}"
                                                            data-delete-name="{{ $participant->name }}">
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
    @can('external-participants.manage')
        <div class="modal modal-blur fade" id="deleteParticipantModal" tabindex="-1"
            aria-labelledby="deleteParticipantModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered">

                <div class="modal-content">

                    <div class="modal-status bg-danger"></div>

                    <div class="modal-body text-center py-4">

                        <i class="ti ti-alert-triangle text-danger fs-1"></i>

                        <h3 id="deleteParticipantModalLabel" class="mt-3">
                            Hapus Peserta?
                        </h3>

                        <p class="text-secondary">
                            Apakah Anda yakin ingin menghapus
                            <strong id="deleteParticipantName"></strong>?
                        </p>

                        <div class="alert alert-warning text-start mb-0">
                            Peserta yang sudah memiliki histori registrasi
                            event sebaiknya dinonaktifkan, bukan dihapus.
                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Batal
                        </button>

                        <form id="deleteParticipantForm" method="POST" data-form data-loading-text="Menghapus...">
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
                    const modal = document.getElementById(
                        'deleteParticipantModal'
                    );

                    if (!modal) {
                        return;
                    }

                    modal.addEventListener(
                        'show.bs.modal',
                        function(event) {
                            const button = event.relatedTarget;

                            if (!button) {
                                return;
                            }

                            const form = document.getElementById(
                                'deleteParticipantForm'
                            );

                            const nameElement = document.getElementById(
                                'deleteParticipantName'
                            );

                            form.action = button.dataset.deleteUrl;
                            nameElement.textContent = button.dataset.deleteName;
                        }
                    );
                });
            </script>
        @endpush
    @endcan

@endsection
