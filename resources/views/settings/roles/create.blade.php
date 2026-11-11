@extends('layouts.app')

@section('title', 'Tambah Role')

@section('content')

    <x-ui.breadcrumb class="mb-3" :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
        ],
        [
            'label' => 'Role Management',
            'url' => route('settings.roles.index'),
        ],
        [
            'label' => 'Tambah Role',
        ],
    ]" />

    <x-ui.page-header title="Tambah Role" subtitle="Buat role baru dan tentukan permission pengguna" />

    <form method="POST" action="{{ route('settings.roles.store') }}" data-form data-loading-text="Menyimpan role...">
        @csrf

        @include('settings.roles._form')

    </form>

@endsection
