<div class="mb-3">
    <h4 class="mb-1">Rundown Kegiatan</h4>
    <div class="text-secondary small">{{ $day->schedules->count() }} kegiatan</div>

    @can('events.update')
        <div class="text-secondary small mt-2">
            Isi baris baru lalu klik Tambah. Ubah kegiatan langsung pada barisnya, lalu klik Simpan.
        </div>
    @endcan
</div>

<div class="table-responsive">
    <table class="table table-sm table-vcenter event-schedules-table">
        <thead>
            <tr>
                <th class="schedule-time">Waktu</th>
                <th>Kegiatan</th>
                <th class="schedule-pic">PIC</th>
                @can('events.update')
                    <th class="schedule-actions text-end">Aksi</th>
                @endcan
            </tr>
        </thead>
        <tbody>
            @forelse ($day->schedules as $schedule)
                @can('events.update')
                    @include('events.partials.schedule-row', ['schedule' => $schedule])
                @else
                    <tr>
                        <td class="schedule-time" data-label="Waktu">
                            {{ substr($schedule->start_time, 0, 5) }} - {{ substr($schedule->end_time, 0, 5) }}
                        </td>
                        <td data-label="Kegiatan">
                            <div class="fw-semibold">{{ $schedule->title }}</div>
                            @if ($schedule->description)
                                <div class="text-secondary small mt-1">{{ $schedule->description }}</div>
                            @endif
                        </td>
                        <td class="schedule-pic" data-label="PIC">{{ $schedule->pic ?: '-' }}</td>
                    </tr>
                @endcan
            @empty
                @cannot('events.update')
                    <tr>
                        <td colspan="3" class="text-center text-secondary py-4">Belum ada rundown kegiatan.</td>
                    </tr>
                @endcannot
            @endforelse

            @can('events.update')
                @include('events.partials.schedule-row', ['schedule' => null])
            @endcan
        </tbody>
    </table>
</div>
