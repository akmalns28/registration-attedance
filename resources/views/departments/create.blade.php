@extends('layouts.app')

@section('title', 'Tambah Departemen')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Master Data / Department Management
                    </div>

                    <h2 class="page-title">
                        Tambah Departemen
                    </h2>

                    <div class="text-secondary mt-1">
                        Tambahkan departemen baru ke dalam sistem.
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('departments.index') }}" class="btn btn-outline-secondary">
                        <i class="ti ti-arrow-left me-2"></i>
                        Kembali
                    </a>

                </div>

            </div>

        </div>

        <form method="POST" action="{{ route('departments.store') }}" data-form data-loading-text="Menyimpan departemen..."
            class="mt-3">
            @csrf

            @include('departments._form')

        </form>

    </div>

@endsection
