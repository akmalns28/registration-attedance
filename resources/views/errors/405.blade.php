@extends('layouts.error')

@section('title', '405 - Method Not Allowed')

@section('content')

    <div class="empty">

        <div class="empty-header">
            405
        </div>

        <p class="empty-title">
            Metode Permintaan Tidak Diizinkan
        </p>

        <p class="empty-subtitle text-secondary">
            Halaman yang Anda akses tidak mendukung
            metode permintaan yang digunakan.

            Silakan kembali ke halaman sebelumnya
            atau akses aplikasi melalui navigasi yang tersedia.
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
