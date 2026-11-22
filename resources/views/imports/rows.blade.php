@php
    $statusLabels = ['create' => 'Siap dibuat', 'invalid' => 'Invalid', 'existing' => 'Existing — skip', 'duplicate' => 'Duplikat — skip', 'created' => 'Tersimpan', 'skipped' => 'Dilewati', 'failed' => 'Gagal'];
    $statusColors = ['create' => 'primary', 'invalid' => 'danger', 'existing' => 'secondary', 'duplicate' => 'warning', 'created' => 'success', 'skipped' => 'secondary', 'failed' => 'danger'];
    if ($module === 'organizations') {
        $statusLabels['create'] = 'Siap disimpan';
        $statusLabels['existing'] = 'Tanpa perubahan';
    }
    $legacyColumns = array_flip(\App\Services\BulkImportService::LEGACY_HEADERS);
@endphp
<x-ui.card title="Detail Baris">
    <form method="GET" action="{{ request()->url() }}" class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div><label for="import-status" class="form-label">Hasil Validasi</label><select id="import-status" name="status" class="form-select"><option value="">Semua baris</option>
            @foreach ($statusLabels as $key => $label)
                @if (($snapshot['state'] === 'preview') === in_array($key, ['create', 'invalid', 'existing', 'duplicate'], true))
                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                @endif
            @endforeach
        </select></div>
        <x-ui.button type="submit" variant="secondary" outline>Filter</x-ui.button>
    </form>
    <div class="table-responsive">
    <table class="table table-vcenter import-preview-table mobile-card-table" role="table" aria-label="Detail Baris">
        <thead><tr><th>Baris</th>@foreach ($definition['columns'] as $column)<th>{{ $column }}</th>@endforeach<th>Hasil</th><th>Validasi</th></tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr role="row">
                <td class="mobile-row-title" role="cell"><span class="d-md-none">Baris </span>{{ $row['row'] }}</td>
                @foreach ($definition['columns'] as $column)
                    <td class="d-none d-md-table-cell">@include('imports.cell-value')</td>
                @endforeach
                <td data-label="Hasil" role="cell"><span class="badge bg-{{ $statusColors[$row['status']] }}-lt">{{ $statusLabels[$row['status']] }}</span></td>
                <td class="import-row-validation" data-label="Validasi" role="cell">
                    @foreach ($row['errors'] as $error)<div class="text-danger">{{ $error }}</div>@endforeach
                    @foreach ($row['warnings'] as $warning)<div class="text-secondary">{{ $warning }}</div>@endforeach
                    @if (!$row['errors'] && !$row['warnings'])<span class="d-md-none">—</span>@endif
                </td>
                <td class="d-md-none import-row-data" role="cell">
                    <details>
                        <summary class="fw-medium text-primary">Lihat data baris</summary>
                        <dl class="mb-0">
                            @foreach ($definition['columns'] as $column)
                                <dt>{{ $column }}</dt><dd>@include('imports.cell-value')</dd>
                            @endforeach
                        </dl>
                    </details>
                </td>
            </tr>
        @empty<tr><td colspan="{{ count($definition['columns']) + 3 }}" class="text-center text-secondary py-4">Tidak ada baris pada status ini.</td></tr>@endforelse
        </tbody>
    </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
</x-ui.card>
