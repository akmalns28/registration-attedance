@extends('layouts.app')
@section('title', 'Import — '.$definition['title'])
@section('content')
    <x-ui.page-header :title="'Import — '.$definition['title']">
        <x-slot:actions>
            <x-ui.button :href="route($definition['route'].'.index')" variant="secondary" outline>Kembali ke Daftar</x-ui.button>
            <x-ui.button :href="route($definition['route'].'.import.template')" outline><x-ui.icon name="download" class="me-2" />Template Excel</x-ui.button>
            <x-ui.button :href="route($definition['route'].'.import.template', ['format' => 'csv'])" outline><x-ui.icon name="download" class="me-2" />Template CSV</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    @if ($module === 'users')
        <x-ui.alert variant="warning" :dismissible="false">Impor hanya menyiapkan akun lokal nonaktif. Identitas Keycloak belum diverifikasi; akun Keycloak dan password tidak dibuat. Administrator harus memverifikasi identitas sebelum mengaktifkan akun melalui User Management. Role super-admin tidak dapat diimpor.</x-ui.alert>
    @elseif ($module === 'organizations')
        <p class="text-secondary">Impor membuat atau memperbarui organisasi berdasarkan kode. Seluruh berkas harus valid sebelum perubahan disimpan.</p>
    @else
        <p class="text-secondary">Impor hanya membuat data baru. Data existing dan duplikat akan dilewati tanpa ditimpa.</p>
    @endif
    <x-ui.card title="Unggah Berkas">
        @if ($module === 'organizations')
            <p>Gunakan template di atas. Ulangi kode, nama, dan status induk pada setiap baris terkait. Isi status dengan Aktif atau Tidak Aktif. Active dan Inactive juga diterima.</p>
            <p>Untuk baris entitas saja, kosongkan kolom departemen dan divisi. Isi kolom departemen saat menambahkan divisi.</p>
        @elseif ($module === 'external-participants')
            <p>Gunakan kolom <code>{{ implode(', ', $definition['columns']) }}</code>. Hanya kolom <code>name</code> yang wajib diisi. Kolom lainnya opsional. Jika mengisi <code>status</code>, gunakan Aktif, Tidak Aktif, Active, atau Inactive.</p>
        @else
            <p>Gunakan kolom <code>{{ implode(', ', $definition['columns']) }}</code>. Isi <code>status</code> dengan Aktif atau Tidak Aktif. Active dan Inactive juga diterima.</p>
        @endif
        <form method="POST" action="{{ route($definition['route'].'.import.preview') }}" enctype="multipart/form-data" data-form data-loading-text="Memvalidasi berkas...">
            @csrf
            <div class="mb-3">
                <label for="import-file" class="form-label required">Berkas impor</label>
                <input id="import-file" type="file" name="file" class="form-control @error('file') is-invalid @enderror" accept=".xlsx,.xls,.csv" required aria-describedby="import-file-help @error('file') import-file-error @enderror">
                <div id="import-file-help" class="form-hint">
                    XLSX, XLS, atau CSV UTF-8 · maksimal {{ number_format(config('bulk_imports.max_upload_kb') / 1024, 1) }} MB · {{ number_format(config('bulk_imports.max_rows'), 0, ',', '.') }} baris.
                    @if ($module === 'organizations')
                        Gunakan format Text pada kolom kode untuk menjaga nol di depan.
                    @elseif ($module !== 'users')
                        Gunakan format Text untuk @if ($module === 'employees') NIP, kode organisasi, dan @endif nomor telepon saat mengedit di Excel agar nol di depan tetap tersimpan.
                    @endif
                    Gunakan nilai biasa tanpa formula atau macro.
                </div>
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
        <x-ui.card title="Referensi Organisasi Aktif" class="mt-3">
            <p class="text-secondary">
                Gunakan kode atau nama unik untuk <code>entity</code>, <code>department</code>, dan <code>division</code>.
                Pilih departemen dalam entitasnya dan divisi dalam departemennya. Divisi boleh dikosongkan.
                Untuk departemen lama tanpa entitas, kosongkan <code>entity</code>.
            </p>
            <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Entitas</th><th>Departemen</th><th>Divisi</th></tr></thead><tbody>
                @forelse ($departments as $department)
                    <tr>
                        <td>{{ $department->entity?->organizationLabel() ?? 'Belum ditetapkan' }}</td>
                        <td>{{ $department->organizationLabel() }}</td>
                        <td>
                            @forelse ($department->divisions as $division)
                                <div>{{ $division->organizationLabel() }}</div>
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                @empty<tr><td colspan="3">Belum ada departemen aktif. Siapkan departemen sebelum mengimpor karyawan.</td></tr>@endforelse
            </tbody></table></div>
        </x-ui.card>
    @elseif ($module === 'users')
        <x-ui.card title="Role yang Dapat Dipakai" class="mt-3"><div class="d-flex flex-wrap gap-2">@foreach ($roles as $role)<code>{{ $role }}</code>@endforeach</div></x-ui.card>
    @elseif ($module === 'external-participants')
        <p class="text-secondary mt-3">Baris dengan email, telepon, atau kombinasi nama dan organisasi yang sama ditandai dan dilewati.</p>
    @endif
@endsection
