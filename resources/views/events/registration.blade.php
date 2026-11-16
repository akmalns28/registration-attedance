@extends('layouts.auth')

@section('title', 'Registrasi Event')

@section('content')
    <h1 class="text-center mb-4">Registrasi Event</h1>

    <x-ui.card :title="$registration->event->title">
        <dl class="mb-4">
            <dt class="text-secondary">Nomor Registrasi</dt>
            <dd class="text-break mb-3">{{ $registration->registration_number }}</dd>

            <dt class="text-secondary">Status Registrasi</dt>
            <dd class="mt-2">
                <span class="badge {{ $registration->status === \App\Models\EventRegistration::STATUS_CANCELLED ? 'bg-danger-lt' : 'bg-success-lt' }}">
                    {{ $registration->status === \App\Models\EventRegistration::STATUS_CANCELLED ? 'Dibatalkan' : 'Terdaftar' }}
                </span>
            </dd>
        </dl>

        @if ($registration->status === \App\Models\EventRegistration::STATUS_CANCELLED)
            <x-ui.alert variant="danger" :dismissible="false">
                Registrasi ini telah dibatalkan. Hubungi panitia untuk informasi lebih lanjut.
            </x-ui.alert>
        @else
            <p class="text-secondary mb-0">
                Tunjukkan QR Code dari email Anda kepada panitia saat check-in.
            </p>
        @endif
    </x-ui.card>
@endsection
