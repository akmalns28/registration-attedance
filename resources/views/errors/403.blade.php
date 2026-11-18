@extends('layouts.error')

@section('title', '403 - Access Denied')

@section('content')

    <div class="empty">

        <div class="empty-header">
            403
        </div>

        <p class="empty-title">
            Akses Ditolak
        </p>

        <p class="empty-subtitle text-secondary">
            Anda tidak memiliki izin untuk mengakses halaman ini.

            Silakan hubungi administrator jika membutuhkan
            akses tambahan.
        </p>

        <div class="empty-action">

            <a href="{{ auth()->check() && auth()->user()->can('dashboard.view') ? route('dashboard') : route('login') }}"
                class="btn btn-primary">
                <x-ui.icon name="home" class="me-2" />
                {{ auth()->check() && auth()->user()->can('dashboard.view') ? 'Dashboard' : 'Login' }}
            </a>

        </div>

    </div>

@endsection
