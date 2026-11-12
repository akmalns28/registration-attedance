@extends('layouts.app')

@section('title', 'Edit Peserta Eksternal')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Master Data / External Participant Management
                    </div>

                    <h2 class="page-title">
                        Edit Peserta Eksternal
                    </h2>

                    <div class="text-secondary mt-1">
                        Perbarui informasi peserta:
                        <strong>{{ $participant->name }}</strong>
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

        <form method="POST" action="{{ route('external-participants.update', $participant) }}" data-form
            data-loading-text="Memperbarui peserta..." class="mt-3">
            @csrf
            @method('PUT')

            @include('external-participants._form')

        </form>

    </div>

@endsection
