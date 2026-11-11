@extends('layouts.app')

@section('title', 'Edit Karyawan')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Master Data / Employee Management
                    </div>

                    <h2 class="page-title">
                        Edit Karyawan
                    </h2>

                    <div class="text-secondary mt-1">
                        Perbarui informasi karyawan:
                        <strong>{{ $employee->name }}</strong>
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">
                        <i class="ti ti-arrow-left me-2"></i>
                        Kembali
                    </a>

                </div>

            </div>

        </div>

        <form method="POST" action="{{ route('employees.update', $employee) }}" data-form
            data-loading-text="Memperbarui karyawan..." class="mt-3">
            @csrf
            @method('PUT')

            @include('employees._form')

        </form>

    </div>

@endsection
