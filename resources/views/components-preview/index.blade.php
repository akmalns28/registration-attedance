@extends('layouts.app')

@section('title', 'UI Components Preview')

@section('content')

    {{-- Page Header --}}
    <x-ui.page-header title="UI Components" subtitle="Preview dan pengujian komponen Registration Attendance" />

    {{-- Breadcrumb --}}
    <x-ui.breadcrumb class="mb-4" :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
        ],
        [
            'label' => 'UI Components',
        ],
    ]" />

    {{-- Stats --}}
    <div class="row row-deck row-cards mb-4">

        <div class="col-sm-6 col-lg-3">
            <x-ui.stat-card title="Total Events" :value="12" description="5 Upcoming" color="primary" />
        </div>

        <div class="col-sm-6 col-lg-3">
            <x-ui.stat-card title="Registered" :value="250" description="Total Participants" color="success" />
        </div>

        <div class="col-sm-6 col-lg-3">
            <x-ui.stat-card title="Attendance" :value="180" description="72% Attendance" color="info" />
        </div>

        <div class="col-sm-6 col-lg-3">
            <x-ui.stat-card title="Pending" :value="70" description="Not Checked In" color="warning" />
        </div>

    </div>

    {{-- Buttons --}}
    <div class="row mb-4">

        <div class="col-12">

            <x-ui.card title="Buttons">

                <div class="btn-list">

                    <x-ui.button variant="primary">
                        Primary
                    </x-ui.button>

                    <x-ui.button variant="secondary">
                        Secondary
                    </x-ui.button>

                    <x-ui.button variant="success">
                        Success
                    </x-ui.button>

                    <x-ui.button variant="danger">
                        Danger
                    </x-ui.button>

                    <x-ui.button variant="warning">
                        Warning
                    </x-ui.button>

                    <x-ui.button variant="primary" :outline="true">
                        Outline
                    </x-ui.button>

                    <x-ui.button disabled>
                        Disabled
                    </x-ui.button>

                </div>

            </x-ui.card>

        </div>

    </div>

    {{-- Badges --}}
    <div class="row mb-4">

        <div class="col-12">

            <x-ui.card title="Badges">

                <div class="d-flex flex-wrap gap-2">

                    <x-ui.badge variant="primary">
                        Primary
                    </x-ui.badge>

                    <x-ui.badge variant="success">
                        Registered
                    </x-ui.badge>

                    <x-ui.badge variant="warning">
                        Pending
                    </x-ui.badge>

                    <x-ui.badge variant="danger">
                        Cancelled
                    </x-ui.badge>

                    <x-ui.badge variant="info">
                        Information
                    </x-ui.badge>

                </div>

            </x-ui.card>

        </div>

    </div>

    {{-- Alerts --}}
    <div class="row mb-4">

        <div class="col-12">

            <x-ui.card title="Alerts">

                <x-ui.alert variant="success" title="Success">
                    Data berhasil disimpan.
                </x-ui.alert>

                <x-ui.alert variant="danger" title="Error">
                    Terjadi kesalahan saat memproses data.
                </x-ui.alert>

                <x-ui.alert variant="warning" title="Warning">
                    Pastikan seluruh data sudah benar.
                </x-ui.alert>

                <x-ui.alert variant="info" title="Information">
                    Ini adalah contoh komponen alert.
                </x-ui.alert>

            </x-ui.card>

        </div>

    </div>

    {{-- Forms --}}
    <div class="row mb-4">

        <div class="col-md-6">

            <x-ui.card title="Form Inputs">

                <x-ui.form.input name="nik" label="NIK Karyawan" placeholder="Masukkan NIK" required />

                <x-ui.form.input name="name" label="Nama Lengkap" placeholder="Masukkan nama" />

                <x-ui.form.input name="email" label="Email" type="email" placeholder="nama@example.com" />

                <x-ui.form.input name="phone" label="Nomor WhatsApp" placeholder="08xxxxxxxxxx" />

            </x-ui.card>

        </div>

        <div class="col-md-6">

            <x-ui.card title="Form Select">

                <x-ui.form.select name="participant_type" label="Jenis Peserta" :options="[
                    'internal' => 'Internal',
                    'external' => 'External',
                ]" required />

                <x-ui.form.select name="event_status" label="Status Event" :options="[
                    'draft' => 'Draft',
                    'published' => 'Published',
                    'completed' => 'Completed',
                ]" />

            </x-ui.card>

        </div>

    </div>

    {{-- Table --}}
    <div class="row mb-4">

        <div class="col-12">

            <x-ui.card title="Table">

                <x-ui.table>

                    <x-slot:head>
                        <tr>
                            <th>NIK</th>
                            <th>Nama</th>
                            <th>Departemen</th>
                            <th>Status</th>
                        </tr>
                    </x-slot:head>

                    <tr>
                        <td>EMP001</td>
                        <td>Ahmad</td>
                        <td>IT</td>
                        <td>
                            <x-ui.badge variant="success">
                                Active
                            </x-ui.badge>
                        </td>
                    </tr>

                    <tr>
                        <td>EMP002</td>
                        <td>Budi</td>
                        <td>Finance</td>
                        <td>
                            <x-ui.badge variant="success">
                                Active
                            </x-ui.badge>
                        </td>
                    </tr>

                    <tr>
                        <td>EMP003</td>
                        <td>Siti</td>
                        <td>HR</td>
                        <td>
                            <x-ui.badge variant="warning">
                                Inactive
                            </x-ui.badge>
                        </td>
                    </tr>

                </x-ui.table>

            </x-ui.card>

        </div>

    </div>

    {{-- Modal --}}
    <div class="row mb-4">

        <div class="col-12">

            <x-ui.card title="Modal">

                <p class="text-secondary">
                    Klik tombol berikut untuk menguji modal Bootstrap.
                </p>

                <x-ui.button data-bs-toggle="modal" data-bs-target="#demoModal">
                    Open Modal
                </x-ui.button>

            </x-ui.card>

        </div>

    </div>

    <x-ui.modal id="demoModal" title="Demo Modal" size="lg">
        <p>
            Ini adalah contoh reusable modal component.
        </p>

        <x-ui.form.input name="demo_name" label="Nama" placeholder="Masukkan nama" />

        <x-slot:footer>

            <x-ui.button variant="secondary" data-bs-dismiss="modal">
                Close
            </x-ui.button>

            <x-ui.button data-bs-dismiss="modal">
                Save
            </x-ui.button>

        </x-slot:footer>

    </x-ui.modal>

    {{-- Empty State --}}
    <div class="row mb-4">

        <div class="col-12">

            <x-ui.card title="Empty State">

                <x-ui.empty-state title="Belum Ada Event" description="Event belum tersedia. Silakan buat event baru.">
                    <x-slot:action>

                        <x-ui.button href="#">
                            Tambah Event
                        </x-ui.button>

                    </x-slot:action>

                </x-ui.empty-state>

            </x-ui.card>

        </div>

    </div>

@endsection
