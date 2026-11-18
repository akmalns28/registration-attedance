@extends('layouts.app')

@section('title', 'Edit Departemen')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Master Data / Department Management
                    </div>

                    <h2 class="page-title">
                        Edit Departemen
                    </h2>

                    <div class="text-secondary mt-1">
                        Perbarui informasi dan status departemen.
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('departments.index') }}" class="btn btn-outline-secondary">
                        <x-ui.icon name="arrow-left" class="me-2" />
                        Kembali
                    </a>

                </div>

            </div>

        </div>

        <form method="POST" action="{{ route('departments.update', $department) }}" data-form
            data-loading-text="Memperbarui departemen..." class="mt-3">
            @csrf
            @method('PUT')

            @include('departments._form')

        </form>

    </div>

@endsection
