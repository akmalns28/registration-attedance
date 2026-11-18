@extends('layouts.app')

@section('title', 'Tambah Rundown')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Event Management / {{ $event->title }}
                    </div>

                    <h2 class="page-title">
                        Tambah Rundown
                    </h2>

                    <div class="text-secondary mt-1">
                        Day {{ $day->day_number }}
                        —
                        {{ $day->date->format('d/m/Y') }}

                        @if ($day->title)
                            — {{ $day->title }}
                        @endif
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('events.days.show', [$event, $day]) }}#day-rundown" class="btn btn-outline-secondary">
                        <x-ui.icon name="arrow-left" class="me-2" />
                        Kembali
                    </a>

                </div>

            </div>

        </div>

        <form method="POST" action="{{ route('events.days.schedules.store', [$event, $day]) }}" data-form
            data-loading-text="Menyimpan rundown..." class="mt-3">
            @csrf

            @include('events.schedules._form')

        </form>

    </div>

@endsection
