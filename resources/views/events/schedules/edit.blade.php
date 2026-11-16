@extends('layouts.app')

@section('title', 'Edit Rundown')

@section('content')

    <div class="container-xl">

        <div class="page-header d-print-none">

            <div class="row align-items-center g-3">

                <div class="col">

                    <div class="page-pretitle">
                        Event Management / {{ $event->title }}
                    </div>

                    <h2 class="page-title">
                        Edit Rundown
                    </h2>

                    <div class="text-secondary mt-1">
                        {{ $schedule->title }}
                        —
                        Day {{ $day->day_number }}
                        ({{ $day->date->format('d/m/Y') }})
                    </div>

                </div>

                <div class="col-auto ms-auto">

                    <a href="{{ route('events.show', $event) }}#event-days" class="btn btn-outline-secondary">
                        <i class="ti ti-arrow-left me-2"></i>
                        Kembali
                    </a>

                </div>

            </div>

        </div>

        <form method="POST"
            action="{{ route('events.days.schedules.update', [$event, $day, $schedule]) }}"
            data-form data-loading-text="Memperbarui rundown..." class="mt-3">
            @csrf
            @method('PUT')

            @include('events.schedules._form')

        </form>

    </div>

@endsection
