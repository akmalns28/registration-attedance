@extends('layouts.app')

@section('title', 'Analytic')

@section('content')
    @php
        $filters = $analytics['filters'];
        $kpis = $analytics['kpis'];
        $formatPercentage = fn (int|float $value): string => rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%';
        $cards = [
            'total_events' => ['Total Events', 'Event dalam cakupan filter'],
            'total_registrations' => ['Registrasi Aktif', 'Status registered'],
            'participants_attended' => [$kpis['participant_metric'] === 'participants' ? 'Peserta Hadir Unik' : 'Registrations Attended', $kpis['participant_metric'] === 'registrations' ? 'Identitas peserta belum lengkap' : ($filters['event'] ? 'Unik berdasarkan registrasi event' : 'Unik per data master Employee/External')],
            'total_checkins' => ['Total Check-ins', 'Satu registrasi per Event Day'],
            'attendance_rate' => ['Tingkat Kehadiran', $kpis['total_checkins'].' dari '.$kpis['expected_attendance'].' potensi kehadiran'],
            'average_event_rate' => ['Rata-rata Kehadiran Event', $kpis['eligible_events'].' event dengan potensi kehadiran'],
        ];
        $charts = [
            'attendance_trend' => ['Tren Kehadiran', 'Persentase berbobot per tanggal Event Day.'],
            'by_event' => ['Kehadiran per Event', 'Persentase dari potensi kehadiran setiap event.'],
            'participant_types' => ['Employee vs External', 'Distribusi jumlah check-in, bukan jumlah peserta unik.'],
            'by_day' => ['Kehadiran per Event Day', 'Hadir dan potensi kehadiran; persentase tersedia pada tooltip.'],
        ];
    @endphp

    <div data-analytic-page data-url="{{ route('analytic.data') }}">
        <x-ui.page-header title="Analytic" subtitle="Analisis registrasi dan kehadiran lintas event." />

        @if ($errors->any())
            <x-ui.alert variant="danger" :dismissible="false">
                <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        <x-ui.card class="mb-3">
            <form data-analytic-filters action="{{ route('analytic.index') }}" method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-xl-3">
                    <label for="analytic-event" class="form-label">Event</label>
                    <select id="analytic-event" name="event" class="form-select">
                        <option value="">All Events</option>
                        @foreach ($events as $event)
                            <option value="{{ $event->uuid }}" @selected($filters['event'] === $event->uuid)>{{ $event->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label for="analytic-start" class="form-label">Tanggal Mulai</label>
                    <input id="analytic-start" type="date" name="start_date" value="{{ $filters['start_date'] }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label for="analytic-end" class="form-label">Tanggal Selesai</label>
                    <input id="analytic-end" type="date" name="end_date" value="{{ $filters['end_date'] }}" class="form-control">
                </div>
                <div class="col-12 col-md-8 col-xl-3">
                    <label for="analytic-day" class="form-label">Event Day</label>
                    <select id="analytic-day" name="event_day" class="form-select" @disabled(! $filters['event'])>
                        <option value="">{{ $filters['event'] ? 'Semua Event Day' : 'Pilih Event terlebih dahulu' }}</option>
                        @foreach ($analytics['event_days'] as $day)
                            <option value="{{ $day['id'] }}" @selected($filters['event_day'] === $day['id'])>{{ $day['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <button type="submit" data-analytic-refresh class="btn btn-primary w-100">
                        <x-ui.icon name="refresh" class="me-2" /><span data-button-label>Refresh</span>
                    </button>
                </div>
            </form>
            <p class="text-secondary small mb-0 mt-3">
                Filter tanggal mengikuti tanggal Event Day, termasuk hari tidak aktif dan riwayat event.
                Tingkat Kehadiran dihitung dari total potensi kehadiran. Rata-rata Kehadiran Event hanya mencakup event dengan potensi kehadiran lebih dari nol.
            </p>
        </x-ui.card>

        <div data-analytic-feedback class="d-none" role="status" aria-live="polite" aria-atomic="true"></div>
        <p data-analytic-empty class="alert alert-info {{ $kpis['total_events'] ? 'd-none' : '' }}">Tidak ada event yang sesuai dengan filter.</p>
        <div class="row row-cards mb-3 analytic-kpis" aria-label="Ringkasan Analytic" role="group">
            @foreach ($cards as $key => [$label, $description])
                <div class="col-12 col-md-6 col-xl-4">
                    <x-ui.stat-card data-analytic-kpi="{{ $key }}" :title="$label" :description="$description"
                        :value="in_array($key, ['attendance_rate', 'average_event_rate'], true) ? $formatPercentage($kpis[$key]) : number_format($kpis[$key], 0, ',', '.')" />
                </div>
            @endforeach
        </div>

        <div class="row row-cards">
            @foreach ($charts as $key => [$title, $description])
                <div class="col-12 col-lg-6">
                    <x-ui.card :title="$title" class="h-100 analytic-chart-card">
                        <p class="text-secondary small">{{ $description }}</p>
                        <div data-analytic-chart="{{ $key }}" class="analytic-chart" role="img" aria-label="{{ $title }}"></div>
                        <p data-analytic-chart-empty="{{ $key }}" class="text-secondary text-center py-4 d-none">Belum ada data kehadiran dalam cakupan filter.</p>
                    </x-ui.card>
                </div>
            @endforeach
        </div>
        <noscript><div class="alert alert-warning mt-3">Aktifkan JavaScript untuk menampilkan grafik. Filter dan ringkasan tetap dapat digunakan melalui tombol Refresh.</div></noscript>
        <script type="application/json" data-analytic-initial>{!! Illuminate\Support\Js::encode($analytics) !!}</script>
    </div>
@endsection
