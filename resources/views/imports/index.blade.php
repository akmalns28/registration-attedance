@extends('layouts.app')
@section('title', 'Import Excel — '.$definition['title'])
@section('content')
    <x-ui.page-header :title="'Import Excel — '.$definition['title']">
        <x-slot:actions>
            <x-ui.button :href="route($definition['route'].'.index')" variant="secondary" outline>Kembali ke Daftar</x-ui.button>
            <x-ui.button :href="route($definition['route'].'.import.template')" outline><x-ui.icon name="download" class="me-2" />Download Template</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    @if ($module === 'users')
        <x-ui.alert variant="warning" :dismissible="false">Impor hanya menyiapkan akun lokal nonaktif. Identitas Keycloak belum diverifikasi; akun Keycloak dan password tidak dibuat. Administrator harus memverifikasi identitas sebelum mengaktifkan akun melalui User Management. Role super-admin tidak dapat diimpor.</x-ui.alert>
    @else
        <p class="text-secondary">Impor hanya membuat data baru. Data existing dan duplikat akan dilewati tanpa ditimpa.</p>
    @endif
    <x-ui.card title="1. Upload Excel">
        <p>Gunakan kolom <code>{{ implode(', ', $definition['columns']) }}</code>. Isi <code>is_active</code> dengan 1 atau 0. @if ($module === 'users')Akun hasil impor tetap memakai 0.@endif</p>
        <form method="POST" action="{{ route($definition['route'].'.import.preview') }}" enctype="multipart/form-data" data-form data-loading-text="Memvalidasi Excel...">
            @csrf
            <div class="mb-3">
                <label for="import-file" class="form-label required">Berkas Excel</label>
                <input id="import-file" type="file" name="file" class="form-control @error('file') is-invalid @enderror" accept=".xlsx,.xls" required aria-describedby="import-file-help @error('file') import-file-error @enderror">
                <div id="import-file-help" class="form-hint">XLSX/XLS · maksimal {{ number_format(config('bulk_imports.max_upload_kb') / 1024, 1) }} MB · {{ number_format(config('bulk_imports.max_rows'), 0, ',', '.') }} baris. NIP dan telepon harus memakai format Text untuk menjaga nol di depan. Formula dan macro tidak didukung.</div>
                @error('file')<div id="import-file-error" class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if ($errors->any() && !$errors->has('file'))
                <x-ui.alert variant="danger" :dismissible="false">{{ $errors->first() }}</x-ui.alert>
            @endif
            <div class="d-flex flex-wrap justify-content-end gap-2">
                <x-ui.button :href="route($definition['route'].'.index')" variant="secondary" outline>Batal</x-ui.button>
                <x-ui.button type="submit"><x-ui.icon name="eye" class="me-2" />Validasi &amp; Preview</x-ui.button>
            </div>
        </form>
    </x-ui.card>
    @if ($module === 'employees')
        <x-ui.card title="Referensi Departemen Aktif" class="mt-3">
            <p class="text-secondary">Isi <code>department_id</code> menggunakan ID berikut. Impor tidak membuat departemen baru.</p>
            <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>ID</th><th>Kode</th><th>Departemen</th></tr></thead><tbody>
                @forelse ($departments as $department)<tr><td>{{ $department->id }}</td><td>{{ $department->code }}</td><td>{{ $department->name }}</td></tr>
                @empty<tr><td colspan="3">Belum ada departemen aktif. Siapkan departemen sebelum mengimpor karyawan.</td></tr>@endforelse
            </tbody></table></div>
        </x-ui.card>
    @elseif ($module === 'users')
        <x-ui.card title="Role yang Dapat Dipakai" class="mt-3"><div class="d-flex flex-wrap gap-2">@foreach ($roles as $role)<code>{{ $role }}</code>@endforeach</div></x-ui.card>
    @else
        <p class="text-secondary mt-3">Sertakan email atau telepon untuk pemeriksaan duplikasi. Kemiripan kontak atau nama dan organisasi ditandai untuk pemeriksaan dan tidak dibuat otomatis.</p>
    @endif
@endsection
