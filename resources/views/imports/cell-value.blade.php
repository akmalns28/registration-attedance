@php
    $input = $row['input'][$column] ?? $row['data'][$column] ?? $row['data'][$legacyColumns[$column] ?? $column] ?? null;
    $resolved = $row['display'][$column] ?? null;
    $value = $resolved ?? $row['data'][$column] ?? $input;
@endphp
{{ filled($value) ? $value : '—' }}
@if (filled($input) && $input !== $value)
    <div class="small text-secondary">Isian: {{ $input }}</div>
@endif
@if ($column === 'status' && $module === 'users' && $resolved !== null)
    <div class="small text-secondary">Status akun baru: Tidak Aktif</div>
@endif
