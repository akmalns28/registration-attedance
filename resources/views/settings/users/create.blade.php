@extends('layouts.app')

@section('title', 'Tambah Pengguna')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center">

                <div class="col">

                    <div class="page-pretitle">
                        Settings / User Management
                    </div>

                    <h2 class="page-title">
                        Tambah Pengguna
                    </h2>

                    <div class="text-secondary mt-1">
                        Daftarkan pengguna yang diizinkan mengakses aplikasi.
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

        <form method="POST" action="{{ route('settings.users.store') }}" data-form
            data-loading-text="Menyimpan pengguna...">
            @csrf

            @include('settings.users._form')

        </form>

    </div>

@endsection
