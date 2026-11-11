@extends('layouts.app')

@section('title', $title)

@section('content')
    <x-ui.page-header :title="$title" />

    <x-ui.card>
        <x-ui.empty-state title="Fitur belum tersedia" :description="$description">
            <x-slot:action>
                <x-ui.button :href="route('dashboard')">Kembali ke Dashboard</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    </x-ui.card>
@endsection
