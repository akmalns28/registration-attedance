<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Division;
use App\Models\Entity;
use App\Models\Event;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportExportService
{
    public const HEADINGS = ['No.', 'Registration Number', 'Participant Name', 'Participant Type', 'Department', 'Event Day', 'Event Day Date', 'Attendance Status', 'Check-in Time', 'Check-in Operator'];

    public const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private AttendanceReportService $reports) {}

    /** @param array<string, mixed> $filters */
    public function excel(Event $event, array $filters): BinaryFileResponse
    {
        return DB::transaction(function () use ($event, $filters): BinaryFileResponse {
            $context = $this->context($event, $filters);

            return app(Excel::class)->download(new AttendanceReportSpreadsheet($context), $context['filename'].'.xlsx', Excel::XLSX, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::PRIVATE_HEADERS,
            ]);
        });
    }

    /** @param array<string, mixed> $filters */
    public function csv(Event $event, array $filters): StreamedResponse
    {
        return response()->streamDownload(function () use ($event, $filters): void {
            DB::transaction(function () use ($event, $filters): void {
                $context = $this->context($event, $filters);
                $output = fopen('php://output', 'wb');
                fwrite($output, "\xEF\xBB\xBF");
                foreach (self::headerRows($context) as $row) {
                    fputcsv($output, array_map(self::csvValue(...), $row), ',', '"', '', "\r\n");
                }
                foreach ($context['rows'] as $row) {
                    fputcsv($output, array_map(self::csvValue(...), self::displayRow($row)), ',', '"', '', "\r\n");
                }
                if ($context['summary']['expected'] === 0) {
                    fputcsv($output, ['Tidak ada data yang sesuai dengan filter.'], ',', '"', '', "\r\n");
                }
                fclose($output);
            });
        }, $this->filename($event).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', ...self::PRIVATE_HEADERS]);
    }

    /** @param array<string, mixed> $filters */
    public function pdf(Event $event, array $filters): Response
    {
        return DB::transaction(function () use ($event, $filters): Response {
            $context = $this->context($event, $filters);
            $limit = max(1, (int) config('attendance_reports.pdf_max_rows', 2000));
            if ($context['summary']['expected'] > $limit) {
                throw ValidationException::withMessages(['export' => "PDF maksimal {$limit} baris. Persempit filter atau gunakan Excel, CSV, atau Print untuk seluruh hasil."]);
            }

            $response = Pdf::loadView('events.partials.report-document', [...$context, 'printing' => false])
                ->setPaper('a4', 'landscape')->setOptions([
                    'defaultFont' => 'DejaVu Sans', 'defaultMediaType' => 'print', 'isRemoteEnabled' => false,
                    'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
                ])->download($context['filename'].'.pdf');
            $response->headers->add(self::PRIVATE_HEADERS);

            return $response;
        });
    }

    /** @param array<string, mixed> $filters */
    public function print(Event $event, array $filters): StreamedResponse
    {
        return response()->stream(function () use ($event, $filters): void {
            DB::transaction(function () use ($event, $filters): void {
                echo view('events.partials.report-document', [...$this->context($event, $filters), 'printing' => true])->render();
            });
        }, 200, ['Content-Type' => 'text/html; charset=UTF-8', ...self::PRIVATE_HEADERS]);
    }

    /**
     * All formats consume these lazy, ordered rows without DataTables pagination.
     *
     * @param  array<string, mixed>  $filters
     * @return array{event: Event, metadata: array<string, string>, summary: array{expected: int, present: int, not_present: int, percentage: float}, rows: LazyCollection<int, array<string, int|string>>, filename: string}
     */
    private function context(Event $event, array $filters): array
    {
        $day = empty($filters['event_day']) ? null : $event->days()->find($filters['event_day']);
        $department = empty($filters['department_id']) ? null : Department::find($filters['department_id']);
        $entity = empty($filters['entity_id']) ? null : Entity::find($filters['entity_id']);
        $division = empty($filters['division_id']) ? null : Division::find($filters['division_id']);
        $sort = $filters['order'][0] ?? ['column' => 6, 'dir' => 'asc'];
        $search = trim($filters['search']['value'] ?? '');
        $description = [
            'Event Day: '.($day ? 'Day '.$day->day_number.' — '.$day->date->format('Y-m-d').($day->title ? ' — '.$day->title : '') : 'Semua'),
            'Jenis: '.match ($filters['participant_type'] ?? '') {
                'employee' => 'Employee', 'external' => 'External', default => 'Semua'
            },
            'Kehadiran: '.match ($filters['attendance_status'] ?? '') {
                'present' => 'Hadir', 'not_present' => 'Belum Hadir', default => 'Semua'
            },
            'Entitas: '.($entity?->organizationLabel() ?? 'Semua'),
            'Departemen: '.($department?->name ?? 'Semua'),
            'Divisi: '.($division?->organizationLabel() ?? 'Semua'),
            'Pencarian: '.($search !== '' ? $search : 'Semua'),
            'Urutan: '.self::HEADINGS[(int) $sort['column']].' '.$sort['dir'],
        ];

        return [
            'event' => $event,
            'metadata' => [
                'Event' => $event->title,
                'Tanggal Event' => $event->start_date->format('Y-m-d').' — '.$event->end_date->format('Y-m-d'),
                'Lokasi' => $event->location ?: '-',
                'Filter' => implode('; ', $description),
                'Diekspor' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s').' '.config('app.timezone'),
            ],
            'summary' => $this->reports->summary($event, $filters),
            'rows' => $this->reports->exportRows($event, $filters),
            'filename' => $this->filename($event),
        ];
    }

    private function filename(Event $event): string
    {
        $slug = Str::limit(Str::slug($event->title), 80, '') ?: $event->uuid;

        return 'attendance-report-'.$slug.'-'.now()->timezone(config('app.timezone'))->format('Ymd-His');
    }

    /**
     * @param  array{metadata: array<string, string>, summary: array{expected: int, present: int, not_present: int, percentage: float}}  $context
     * @return array<int, array<int, int|float|string>>
     */
    public static function headerRows(array $context): array
    {
        $summary = $context['summary'];

        return [
            ['PT Ari Utsaha Nusantara'], ['LAPORAN KEHADIRAN EVENT'],
            ...collect($context['metadata'])->map(fn (string $value, string $label): array => [$label, $value])->values()->all(),
            [],
            ['Expected Attendance Slots', '', $summary['expected'], 'Present', $summary['present'], 'Not Present', $summary['not_present'], 'Attendance Percentage', '', $summary['percentage'].'%'],
            [], self::HEADINGS,
        ];
    }

    /**
     * @param  array<string, int|string>  $row
     * @return array<int, int|string>
     */
    public static function displayRow(array $row): array
    {
        $row['participant_type'] = $row['participant_type'] === 'employee' ? 'Employee' : 'External';
        $row['attendance_status'] = $row['attendance_status'] === 'present' ? 'Hadir' : 'Belum Hadir';

        return array_values($row);
    }

    private static function csvValue(int|float|string $value): int|float|string
    {
        if (is_string($value) && preg_match('/^(?:[\t\r\n]|[\s\p{Z}\x{FEFF}]*[=+@-])/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
