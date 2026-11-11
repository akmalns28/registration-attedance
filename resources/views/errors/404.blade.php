@extends('layouts.error')

@section('title', '404 - Page Not Found')

@section('content')

    <div class="empty">

        <div class="empty-header">
            404
        </div>

        <p class="empty-title">
            Halaman Tidak Ditemukan
        </p>

        <p class="empty-subtitle text-secondary">
            Halaman yang Anda cari tidak tersedia,
            telah dipindahkan, atau alamat URL yang dimasukkan tidak sesuai.
        </p>

        <div class="empty-action">

            <div class="d-flex flex-wrap justify-content-center gap-2">

                <button type="button" class="btn btn-outline-secondary" onclick="history.back()">
                    <i class="ti ti-arrow-left me-2"></i>
                    Kembali
                </button>

                <a href="{{ auth()->check() && auth()->user()->can('dashboard.view') ? route('dashboard') : route('login') }}"
                    class="btn btn-primary">
                    <i class="ti ti-home me-2"></i>
                    {{ auth()->check() && auth()->user()->can('dashboard.view') ? 'Dashboard' : 'Login' }}
                </a>

            </div>

        </div>

    </div>

@endsection
