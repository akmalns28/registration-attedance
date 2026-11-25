<ul class="list-unstyled d-grid gap-2 mb-4" aria-label="Status ekspor undangan">
    @foreach ($invitationExports as $export)
        <li class="border rounded px-3 py-2 d-flex align-items-center justify-content-between flex-wrap gap-2"
            data-export-status="{{ $export->status }}"
            data-export-url="{{ route('events.participants.invitations.export.status', [$event, $export]) }}">
            <div>
                <strong>{{ ['all' => 'Semua peserta', 'employee' => 'Employee', 'external' => 'External'][$export->participant_type] }}</strong>
                <span class="ms-1 text-secondary" data-export-message role="status">
                    @switch($export->status)
                        @case('pending') Menunggu antrean. @break
                        @case('processing') Menyiapkan undangan ({{ $export->processed_count }}/{{ $export->participant_count }}). @break
                        @case('completed') Siap diunduh ({{ $export->processed_count }} undangan). @break
                        @case('failed') Ekspor gagal. Pilih Export Invitations untuk mencoba kembali. @break
                    @endswitch
                </span>
            </div>
            <a class="btn btn-sm btn-outline-primary {{ $export->isDownloadable() ? '' : 'd-none' }}"
                href="{{ route('events.participants.invitations.export.download', [$event, $export]) }}" data-export-download>
                Unduh ZIP
            </a>
        </li>
    @endforeach
</ul>
