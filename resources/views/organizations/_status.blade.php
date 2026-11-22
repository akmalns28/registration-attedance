<x-ui.badge :variant="$record->is_active ? 'success' : 'secondary'">
    {{ $record->is_active ? 'Aktif' : 'Tidak Aktif' }}
</x-ui.badge>
