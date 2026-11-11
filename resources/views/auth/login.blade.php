@extends('layouts.auth')

@section('title', 'Login')

@section('content')

<div class="text-center mb-4">

    <h1 class="fw-bold">
        Registration Attendance
    </h1>

    <p class="text-secondary">
        Event Registration & Attendance System
    </p>

</div>

<div class="card card-md">

    <div class="card-body">

        <h2 class="h2 text-center mb-4">
            Sign In
        </h2>

        @if(session('error'))

            <x-ui.alert variant="danger">
                {{ session('error') }}
            </x-ui.alert>

        @endif

        <p class="text-secondary text-center mb-4">
            Gunakan akun perusahaan untuk mengakses aplikasi.
        </p>

        <a
            href="{{ route('auth.keycloak.redirect') }}"
            class="btn btn-primary w-100"
        >
            Login with Company SSO
        </a>

    </div>

    <div class="card-footer text-center">

        <span class="text-secondary">
            Powered by Keycloak SSO
        </span>

    </div>

</div>

<div class="text-center text-secondary mt-3">

    &copy; {{ date('Y') }}
    PT Ari Utsaha Nusantara

</div>

@endsection