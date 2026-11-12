@extends('layouts.app')

@section('title', 'Tambah Peserta Eksternal')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Master Data / External Participant Management
                    </div>

                    <h2 class="page-title">
                        Tambah Peserta Eksternal
                    </h2>

                    <div class="text-secondary mt-1">
                        Tambahkan peserta eksternal, tamu undangan,
                        atau mitra bisnis.
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('external-participants.index') }}" class="btn btn-outline-secondary">
                        <i class="ti ti-arrow-left me-2"></i>
                        Kembali
                    </a>

                </div>

            </div>

        </div>

        <form method="POST" action="{{ route('external-participants.store') }}" data-form
            data-loading-text="Menyimpan peserta..." class="mt-3">
            @csrf

            @include('external-participants._form')

        </form>

    </div>

@endsection
