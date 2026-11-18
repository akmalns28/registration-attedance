@extends('layouts.app')

@section('title', 'QR Scanner')

@section('content')
    <x-ui.page-header :title="$fixedEvent ? 'Scan QR: '.$fixedEvent->title : 'QR Scanner'"
        subtitle="Pindai QR undangan untuk memvalidasi registrasi peserta.">
        @if ($fixedEvent)
            <x-slot:actions>
                <a href="{{ route('events.show', $fixedEvent) }}" class="btn btn-outline-secondary">
                    <x-ui.icon name="arrow-left" class="me-1" />Kembali ke Event
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('attendance._scanner')
@endsection
