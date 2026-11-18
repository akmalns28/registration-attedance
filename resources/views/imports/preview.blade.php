@extends('layouts.app')
@section('title', 'Preview Import — '.$definition['title'])
@section('content')
    <x-ui.page-header :title="'2. Preview Import — '.$definition['title']">
        <x-slot:actions>
            <x-ui.button :href="route($definition['route'].'.import.index')" variant="secondary" outline>Upload Ulang</x-ui.button>
            <x-ui.button :href="route($definition['route'].'.import.errors', ['import' => $importId])" variant="secondary" outline><x-ui.icon name="download" class="me-2" />Unduh Detail Validasi</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    <p class="text-secondary">Belum ada data yang disimpan ke database. Preview berlaku {{ config('bulk_imports.ttl_minutes') }} menit; data dan izin akan diperiksa kembali saat konfirmasi.</p>
    @if ($module === 'users')<x-ui.alert variant="warning" :dismissible="false">Semua akun hasil impor akan nonaktif. Identitas Keycloak belum diverifikasi. Role hanya disiapkan pada akun lokal, tanpa membuat akun Keycloak atau password.</x-ui.alert>@endif
    <div class="row row-cards mb-3">
        @foreach (['total' => 'Total Baris', 'valid' => 'Valid', 'invalid' => 'Invalid', 'existing' => 'Existing', 'duplicate' => 'Duplikat', 'create' => 'Akan Dibuat', 'skip' => 'Tidak Dibuat'] as $key => $label)
            <div class="col-6 col-md-4 col-xl-3"><x-ui.stat-card :title="$label" :value="$snapshot['summary'][$key]" /></div>
        @endforeach
    </div>
    @if ($errors->any())<x-ui.alert variant="danger" :dismissible="false">{{ $errors->first() }}</x-ui.alert>@endif
    @include('imports.rows')
    <x-ui.card title="Konfirmasi Import" class="mt-3">
        <form method="POST" action="{{ route($definition['route'].'.import.confirm', ['import' => $importId]) }}" data-form data-loading-text="Mengimpor data...">
            @csrf
            <label class="form-check mb-3"><input type="checkbox" name="confirm" value="1" class="form-check-input" required @disabled(!$snapshot['summary']['create'])><span class="form-check-label">Saya setuju membuat {{ $snapshot['summary']['create'] }} baris valid. Baris invalid, existing, dan duplikat tidak akan dibuat; data existing tidak diubah.</span></label>
            <div class="d-flex flex-wrap justify-content-end gap-2">
                <x-ui.button :href="route($definition['route'].'.import.index')" variant="secondary" outline>Batal</x-ui.button>
                <x-ui.button type="submit" :disabled="!$snapshot['summary']['create']">Konfirmasi Import ({{ $snapshot['summary']['create'] }})</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
