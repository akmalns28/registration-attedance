@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    <div class="page-header d-print-none">

        <div class="row align-items-center">

            <div class="col">

                <h2 class="page-title">
                    Dashboard
                </h2>

                <div class="text-secondary mt-1">
                    Registration Attendance Overview
                </div>

            </div>

        </div>

    </div>

    <div class="row row-deck row-cards mt-3">

        {{-- Total Events --}}
        <div class="col-sm-6 col-lg-3">

            <div class="card stat-card">

                <div class="card-body">

                    <div class="subheader">
                        Total Events
                    </div>

                    <div class="h1 mb-0">
                        0
                    </div>

                </div>

            </div>

        </div>

        {{-- Total Participants --}}
        <div class="col-sm-6 col-lg-3">

            <div class="card stat-card">

                <div class="card-body">

                    <div class="subheader">
                        Total Participants
                    </div>

                    <div class="h1 mb-0">
                        0
                    </div>

                </div>

            </div>

        </div>

        {{-- Total Registered --}}
        <div class="col-sm-6 col-lg-3">

            <div class="card stat-card">

                <div class="card-body">

                    <div class="subheader">
                        Registered
                    </div>

                    <div class="h1 mb-0">
                        0
                    </div>

                </div>

            </div>

        </div>

        {{-- Total Attendance --}}
        <div class="col-sm-6 col-lg-3">

            <div class="card stat-card">

                <div class="card-body">

                    <div class="subheader">
                        Attendance
                    </div>

                    <div class="h1 mb-0">
                        0
                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="row mt-4">

        <div class="col-12">

            <div class="card">

                <div class="card-header">

                    <h3 class="card-title">
                        Welcome to Registration Attendance
                    </h3>

                </div>

                <div class="card-body">

                    <p>
                        Sistem registrasi dan absensi event
                        berbasis QR Code.
                    </p>

                    <span class="badge bg-blue-lt">
                        Development Mode
                    </span>

                </div>

            </div>

        </div>

    </div>

@endsection
