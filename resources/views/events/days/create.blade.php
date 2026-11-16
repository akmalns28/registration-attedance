@extends('layouts.app')

@section('title', 'Tambah Event Day')

@section('content')
    <div class="container-xl">

        <div class="page-header d-print-none">
            <div class="row align-items-center g-3">

                <div class="col">
                    <div class="page-pretitle">
                        Event Management / {{ $event->title }}
                    </div>

                    <h2 class="page-title">
                        Tambah Hari Pelaksanaan
                    </h2>

                    <div class="text-secondary mt-1">
                        Tentukan tanggal dan nama hari pelaksanaan event.
                    </div>
                </div>

            </div>
        </div>

        <form method="POST" action="{{ route('events.days.store', $event) }}" data-form
            data-loading-text="Menyimpan Event Day..." class="mt-3">
            @csrf

            @include('events.days._form')
        </form>

    </div>
@endsection
