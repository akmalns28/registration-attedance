@extends('layouts.app')

@section('title', 'Edit Pengguna')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center">

                <div class="col">

                    <div class="page-pretitle">
                        Settings / User Management
                    </div>

                    <h2 class="page-title">
                        Edit Pengguna
                    </h2>

                    <div class="text-secondary mt-1">
                        Perbarui informasi, role, dan status akses pengguna.
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('settings.users.index') }}" class="btn btn-outline-secondary">
                        <i class="ti ti-arrow-left me-2"></i>
                        Kembali
                    </a>

                </div>

            </div>

        </div>

        <form method="POST" action="{{ route('settings.users.update', $user) }}" data-form
            data-loading-text="Memperbarui pengguna...">
            @csrf
            @method('PUT')

            @include('settings.users._form')

        </form>

    </div>

@endsection
