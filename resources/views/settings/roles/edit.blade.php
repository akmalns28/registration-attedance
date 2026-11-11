@extends('layouts.app')

@section('title', 'Edit Role')

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
            'label' => 'Edit Role',
        ],
    ]" />

    <x-ui.page-header title="Edit Role" subtitle="Perbarui informasi dan permission role" />

    <form method="POST" action="{{ route('settings.roles.update', $role) }}" data-form
        data-loading-text="Memperbarui role...">
        @csrf
        @method('PUT')

        @include('settings.roles._form')

    </form>

@endsection
