@extends('layouts.app')

@section('title', 'Event Management')

@section('content')
    <div class="container-xl">

        @error('event')
            <div class="alert alert-danger" role="alert">{{ $message }}</div>
        @enderror

        {{-- Page Header --}}
        <div class="page-header d-print-none">
            <div class="row align-items-center g-3">

                <div class="col">
                    <div class="page-pretitle">
                        Event Management
                    </div>

                    <h2 class="page-title">
                        Daftar Event
                    </h2>

                    <div class="text-secondary mt-1">
                        Kelola event, jadwal pelaksanaan, rundown,
                        dan peserta kegiatan.
                    </div>
                </div>

                @can('events.create')
                    <div class="col-auto ms-auto">
                        <a href="{{ route('events.create') }}" class="btn btn-primary">
                            <i class="ti ti-plus me-2"></i>
                            Tambah Event
                        </a>
                    </div>
                @endcan

            </div>
        </div>

        {{-- Summary --}}
        <div class="row row-cards mt-3">

            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Total Event</div>
                        <div class="h1 mb-0">
                            {{ $events->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Draft</div>
                        <div class="h1 mb-0">
                            {{ $events->where('status', 'draft')->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Published</div>
                        <div class="h1 text-success mb-0">
                            {{ $events->where('status', 'published')->count() }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Closed / Cancelled</div>
                        <div class="h1 mb-0">
                            {{ $events->whereIn('status', ['closed', 'cancelled'])->count() }}
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- Event Table --}}
        <div class="row row-cards mt-3">
            <div class="col-12">
                <div class="card">

                    <div class="card-header">
                        <h3 class="card-title">
                            Daftar Event
                        </h3>

                        <div class="card-actions">
                            <span class="badge bg-primary-lt">
                                {{ $events->count() }} Event
                            </span>
                        </div>
                    </div>

                    <div class="card-body">

                        <div class="table-responsive">
                            <table id="eventsTable" class="table table-vcenter table-hover w-100" data-datatable
                                data-export="true" data-filename="event-management">
                                <thead>
                                    <tr>
                                        <th class="no-sort no-export">No.</th>
                                        <th>Nama Event</th>
                                        <th>Tanggal Mulai</th>
                                        <th>Tanggal Selesai</th>
                                        <th>Lokasi</th>
                                        <th>Kapasitas</th>
                                        <th>Status</th>
                                        <th class="no-sort no-export text-end text-nowrap w-1">
                                            Action
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($events as $event)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>

                                            <td>
                                                <a href="{{ route('events.show', $event) }}" class="fw-semibold">
                                                    {{ $event->title }}
                                                </a>

                                                <div class="text-secondary small">
                                                    {{ $event->isMultiDay() ? 'Multi-day Event' : 'Single-day Event' }}
                                                </div>
                                            </td>

                                            <td>
                                                {{ $event->start_date->format('d/m/Y') }}
                                            </td>

                                            <td>
                                                {{ $event->end_date->format('d/m/Y') }}
                                            </td>

                                            <td>
                                                {{ $event->location ?: '-' }}
                                            </td>

                                            <td>
                                                {{ $event->capacity ? number_format($event->capacity, 0, ',', '.') : 'Unlimited' }}
                                            </td>

                                            <td>
                                                @switch($event->status)
                                                    @case('draft')
                                                        <span class="badge bg-secondary-lt">
                                                            Draft
                                                        </span>
                                                    @break

                                                    @case('published')
                                                        <span class="badge bg-success-lt">
                                                            Published
                                                        </span>
                                                    @break

                                                    @case('closed')
                                                        <span class="badge bg-azure-lt">
                                                            Closed
                                                        </span>
                                                    @break

                                                    @case('cancelled')
                                                        <span class="badge bg-danger-lt">
                                                            Cancelled
                                                        </span>
                                                    @break
                                                @endswitch
                                            </td>

                                            <td class="text-end text-nowrap">
                                                <div class="btn-list flex-nowrap justify-content-end">

                                                    <a href="{{ route('events.show', $event) }}"
                                                        class="btn btn-sm btn-outline-secondary">
                                                        <i class="ti ti-eye me-1"></i>
                                                        Detail
                                                    </a>

                                                    @can('events.update')
                                                        <a href="{{ route('events.edit', $event) }}"
                                                            class="btn btn-sm btn-outline-primary">
                                                            <i class="ti ti-edit"></i>
                                                        </a>
                                                    @endcan

                                                    @can('events.delete')
                                                        @if ($event->deletionProhibitedReason() === null)
                                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                                aria-label="Hapus Event"
                                                                data-bs-toggle="modal" data-bs-target="#deleteEventModal"
                                                                data-delete-url="{{ route('events.destroy', $event) }}"
                                                                data-delete-name="{{ $event->title }}">
                                                                <i class="ti ti-trash"></i>
                                                            </button>
                                                        @endif
                                                    @endcan

                                                </div>
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

    {{-- Delete Modal --}}
    @can('events.delete')
        <div class="modal modal-blur fade" id="deleteEventModal" tabindex="-1" aria-labelledby="deleteEventModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-status bg-danger"></div>

                    <div class="modal-body text-center py-4">

                        <i class="ti ti-alert-triangle text-danger fs-1"></i>

                        <h3 id="deleteEventModalLabel" class="mt-3">
                            Hapus Event?
                        </h3>

                        <p class="text-secondary">
                            Apakah Anda yakin ingin menghapus
                            <strong id="deleteEventName"></strong>?
                        </p>

                        <div class="alert alert-warning text-start mb-0">
                            Event hanya dapat dihapus jika berstatus Draft
                            tanpa riwayat registrasi, riwayat notifikasi, atau hari pelaksanaan.
                            Hapus rundown sebelum menghapus hari pelaksanaan.
                            Gunakan status Cancelled untuk membatalkan event yang memiliki riwayat operasional.
                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Batal
                        </button>

                        <form id="deleteEventForm" method="POST" data-form data-loading-text="Menghapus...">
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
                document.addEventListener('DOMContentLoaded', () => {
                    const modal = document.getElementById('deleteEventModal');

                    if (!modal) return;

                    modal.addEventListener('show.bs.modal', event => {
                        const button = event.relatedTarget;

                        if (!button) return;

                        document.getElementById('deleteEventForm').action =
                            button.dataset.deleteUrl;

                        document.getElementById('deleteEventName').textContent =
                            button.dataset.deleteName;
                    });
                });
            </script>
        @endpush
    @endcan

@endsection
