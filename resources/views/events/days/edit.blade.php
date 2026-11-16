@extends('layouts.app')

@section('title', 'Edit Event Day')

@section('content')
    <div class="container-xl">

        <div class="page-header d-print-none">
            <div class="row align-items-center g-3">

                <div class="col">
                    <div class="page-pretitle">
                        Event Management / {{ $event->title }}
                    </div>

                    <h2 class="page-title">
                        Edit Hari Pelaksanaan
                    </h2>

                    <div class="text-secondary mt-1">
                        Day {{ $day->day_number }}
                        — {{ $day->date->format('d/m/Y') }}
                    </div>
                </div>

            </div>
        </div>

        <form method="POST" action="{{ route('events.days.update', [$event, $day]) }}" data-form
            data-loading-text="Memperbarui Event Day..." class="mt-3">
            @csrf
            @method('PUT')

            @include('events.days._form')
        </form>

    </div>
@endsection
