<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Kehadiran — {{ $event->title }} - {{ config('app.name') }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #182433; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.4; }
        h1 { font-size: 16px; margin: 0 0 3px; }
        h2 { font-size: 13px; margin: 0 0 10px; }
        .metadata { margin-bottom: 10px; }
        .metadata p { margin: 3px 0; overflow-wrap: break-word; }
        .summary { width: 100%; border-collapse: collapse; margin: 10px 0 14px; }
        .summary td { border: 1px solid #b5bdc7; padding: 6px; width: 25%; }
        .summary strong { display: block; font-size: 13px; }
        .report-table { table-layout: fixed; width: 100%; border-collapse: collapse; }
        .report-table th, .report-table td { border: 1px solid #b5bdc7; padding: 5px 4px; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
        .report-table th { background: #e7eef8; text-align: left; font-weight: bold; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        .empty { text-align: center; padding: 18px; }
        .print-controls { padding: 12px; margin-bottom: 16px; background: #f1f5f9; font-size: 14px; }
        .print-controls button { cursor: pointer; padding: 8px 16px; margin-right: 12px; }
        .footer { margin-top: 12px; color: #526070; }
        @media screen { body { padding: 20px; } .report-scroll { overflow-x: auto; } .report-table { min-width: 1050px; } }
        @media print { .print-controls { display: none !important; } .report-scroll { overflow: visible; } body { padding: 0; } }
    </style>
</head>
<body>
    @if ($printing)
        <div class="print-controls">
            <button type="button" onclick="window.print()">Print laporan</button>
            <span>Gunakan kertas A4 landscape. Laporan mencakup seluruh hasil filter.</span>
        </div>
    @endif
    <h1>PT Ari Utsaha Nusantara</h1>
    <h2>LAPORAN KEHADIRAN EVENT</h2>
    <div class="metadata">
        @foreach ($metadata as $label => $value)
            <p><b>{{ $label }}:</b> {{ $value }}</p>
        @endforeach
    </div>
    <table class="summary" aria-label="Ringkasan kehadiran">
        <tr>
            <td>Expected Attendance Slots<strong>{{ number_format($summary['expected'], 0, ',', '.') }}</strong></td>
            <td>Present<strong>{{ number_format($summary['present'], 0, ',', '.') }}</strong></td>
            <td>Not Present<strong>{{ number_format($summary['not_present'], 0, ',', '.') }}</strong></td>
            <td>Attendance Percentage<strong>{{ number_format($summary['percentage'], 2, ',', '.') }}%</strong></td>
        </tr>
    </table>
    <div class="report-scroll">
        <table class="report-table">
            <colgroup>
                @foreach ([4, 12, 14, 8, 10, 14, 9, 8, 9, 12] as $width)
                    <col style="width: {{ $width }}%">
                @endforeach
            </colgroup>
            <thead><tr>
                @foreach (\App\Services\AttendanceReportExportService::HEADINGS as $heading)
                    <th scope="col">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr data-report-row>
                        @foreach (\App\Services\AttendanceReportExportService::displayRow($row) as $value)
                            <td>{{ $value }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty">Tidak ada data yang sesuai dengan filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="footer">Satu baris adalah satu registrasi aktif pada satu Event Day. Waktu check-in menggunakan {{ config('app.timezone') }}.</p>
</body>
</html>
