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

    <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Metode check-in">
        <li class="nav-item" role="presentation">
            <a class="nav-link active" id="attendance-qr-tab" href="#attendance-qr" data-bs-toggle="tab" role="tab"
                aria-controls="attendance-qr" aria-selected="true">Scan QR</a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link" id="attendance-manual-tab" href="#attendance-manual" data-bs-toggle="tab" role="tab"
                aria-controls="attendance-manual" aria-selected="false">Manual Check-in</a>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane active show" id="attendance-qr" role="tabpanel" aria-labelledby="attendance-qr-tab">
            @include('attendance._scanner')
        </div>
        <div class="tab-pane" id="attendance-manual" role="tabpanel" aria-labelledby="attendance-manual-tab">
            @include('attendance._manual')
        </div>
    </div>
@endsection
