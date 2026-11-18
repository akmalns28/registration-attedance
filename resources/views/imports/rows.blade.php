@php
    $statusLabels = ['create' => 'Siap dibuat', 'invalid' => 'Invalid', 'existing' => 'Existing — skip', 'duplicate' => 'Duplikat — skip', 'created' => 'Tersimpan', 'skipped' => 'Dilewati', 'failed' => 'Gagal'];
    $statusColors = ['create' => 'primary', 'invalid' => 'danger', 'existing' => 'secondary', 'duplicate' => 'warning', 'created' => 'success', 'skipped' => 'secondary', 'failed' => 'danger'];
@endphp
<x-ui.card title="Detail Baris">
    <form method="GET" action="{{ request()->url() }}" class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div><label for="import-status" class="form-label">Status</label><select id="import-status" name="status" class="form-select"><option value="">Semua baris</option>
            @foreach ($statusLabels as $key => $label)
                @if (($snapshot['state'] === 'preview') === in_array($key, ['create', 'invalid', 'existing', 'duplicate'], true))
                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                @endif
            @endforeach
        </select></div>
        <x-ui.button type="submit" variant="secondary" outline>Filter</x-ui.button>
    </form>
    <div class="table-responsive"><table class="table table-vcenter import-preview-table"><thead><tr><th>Baris Excel</th>@foreach ($definition['columns'] as $column)<th>{{ $column }}</th>@endforeach<th>Status</th><th>Validasi</th></tr></thead><tbody>
        @forelse ($rows as $row)
            <tr><td>{{ $row['row'] }}</td>@foreach ($definition['columns'] as $column)<td>{{ $row['data'][$column] ?? '—' }}</td>@endforeach
                <td><span class="badge bg-{{ $statusColors[$row['status']] }}-lt">{{ $statusLabels[$row['status']] }}</span></td>
                <td>@foreach ($row['errors'] as $error)<div class="text-danger">{{ $error }}</div>@endforeach @foreach ($row['warnings'] as $warning)<div class="text-secondary">{{ $warning }}</div>@endforeach</td>
            </tr>
        @empty<tr><td colspan="{{ count($definition['columns']) + 3 }}" class="text-center text-secondary py-4">Tidak ada baris pada status ini.</td></tr>@endforelse
    </tbody></table></div>
    <div class="mt-3">{{ $rows->links() }}</div>
</x-ui.card>
