@extends('layouts.app')
@section('title', 'Hasil Import — '.$definition['title'])
@section('content')
    <x-ui.page-header :title="($snapshot['state'] === 'completed' ? 'Import Completed — ' : 'Import Belum Selesai — ').$definition['title']">
        <x-slot:actions>
            <x-ui.button :href="route($definition['route'].'.import.errors', ['import' => $importId])" variant="secondary" outline><x-ui.icon name="download" class="me-2" />Unduh Detail Hasil</x-ui.button>
            <x-ui.button :href="route($definition['route'].'.index')">Lihat Daftar Terbaru</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    @if ($snapshot['state'] !== 'completed')<x-ui.alert variant="warning" :dismissible="false">Impor sedang berjalan atau terhenti. Ringkasan hanya mencakup hasil yang sudah tercatat. Periksa daftar sebelum mengunggah ulang; konfirmasi berulang tidak akan menjalankan impor lagi.</x-ui.alert>@endif
    <div class="row row-cards mb-3">
        @foreach (($module === 'organizations'
            ? ['created' => 'Disimpan', 'skipped' => 'Tanpa Perubahan', 'failed' => 'Gagal']
            : ['created' => 'Created', 'skipped' => 'Skipped', 'failed' => 'Failed']) as $key => $label)
            <div class="col-12 col-sm-4"><x-ui.stat-card :title="$label" :value="$snapshot['result_summary'][$key] ?? 0" /></div>
        @endforeach
    </div>
    @if ($module === 'users')<p class="text-secondary">Akun baru tetap nonaktif. Verifikasi identitas Keycloak sebelum aktivasi melalui User Management.</p>@endif
    @include('imports.rows')
@endsection
