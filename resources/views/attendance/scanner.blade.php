@extends('layouts.app')

@section('title', 'QR Scanner')

@section('content')
    <x-ui.page-header :title="$fixedEvent ? 'Scan QR: '.$fixedEvent->title : 'QR Scanner'"
        subtitle="Pindai QR undangan untuk memvalidasi registrasi peserta.">
        @if ($fixedEvent)
            <x-slot:actions>
                <a href="{{ route('events.show', $fixedEvent) }}" class="btn btn-outline-secondary">
                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Kembali ke Event
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('attendance._scanner')
@endsection
