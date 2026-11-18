@extends('layouts.app')

@section('title', 'Tambah Event')

@section('content')
    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Event Management
                    </div>

                    <h2 class="page-title">
                        Tambah Event
                    </h2>

                    <div class="text-secondary mt-1">
                        Buat event baru dan tentukan periode pelaksanaannya.
                    </div>

                </div>

                <div class="col-auto ms-auto">
                    <a href="{{ route('events.index') }}" class="btn btn-outline-secondary">
                        <x-ui.icon name="arrow-left" class="me-2" />
                        Kembali
                    </a>
                </div>

            </div>

        </div>

        <form method="POST" action="{{ route('events.store') }}" class="mt-3" data-form
            data-loading-text="Menyimpan event...">
            @csrf

            @include('events._form')

        </form>

    </div>
@endsection
