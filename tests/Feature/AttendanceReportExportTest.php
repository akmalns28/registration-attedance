<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\AttendanceReportExportService;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AttendanceReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        $this->freezeTime();
    }

    #[TestWith(['excel'])]
    #[TestWith(['csv'])]
    #[TestWith(['print'])]
    public function test_exports_include_every_ordered_active_registration_day_and_the_same_summary(string $format): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());
        $filters = ['order' => [['column' => 2, 'dir' => 'desc']], 'start' => 4, 'length' => 1];
        $expected = $this->getJson(route('events.reports.data', $fixture['event']).'?'.http_build_query([...$filters, 'start' => 0, 'length' => 100]))->assertOk()->json('data');
        $response = $this->getJson($this->url($fixture['event'], $format, $filters))->assertOk();
        $rows = $this->exportedRows($response, $format);

        $this->assertSame(array_map(fn (array $row): array => array_map(strval(...), AttendanceReportExportService::displayRow($row)), $expected), $rows);
        $this->assertCount(9, $rows);
        $this->assertSame(6, Attendance::count());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('Cancelled Participant', json_encode($rows));
        $this->assertStringNotContainsString('Other Event Participant', json_encode($rows));
        if ($format !== 'print') {
            $this->assertMatchesRegularExpression('/filename=attendance-report-raker-export-\d{8}-\d{6}\.(xlsx|csv)/', $response->headers->get('Content-Disposition'));
        }
        if ($format === 'excel') {
            $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    public function test_workbook_contains_typed_safe_text_dates_times_summary_and_usable_formatting(): void
    {
        $fixture = $this->fixture();
        $fixture['alice']->employee->update(['name' => '=HYPERLINK("https://example.test")']);
        $fixture['event']->update(['title' => '+Raker Export', 'location' => '@location']);
        $workbook = $this->workbook($this->actingAs($this->user())->getJson($this->url($fixture['event'], 'excel'))->assertOk());
        $sheet = $workbook->getActiveSheet();

        $this->assertSame('PT Ari Utsaha Nusantara', $sheet->getCell('A1')->getValue());
        $this->assertSame('LAPORAN KEHADIRAN EVENT', $sheet->getCell('A2')->getValue());
        $this->assertSame('+Raker Export', $sheet->getCell('B3')->getValue());
        $this->assertSame('@location', $sheet->getCell('B5')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B3')->getDataType());
        $this->assertSame('=HYPERLINK("https://example.test")', $sheet->getCell('C12')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C12')->getDataType());
        $this->assertSame('2027-01-15', $sheet->getCell('G12')->getFormattedValue());
        $this->assertSame('08:15:00', $sheet->getCell('I12')->getFormattedValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G12')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('I12')->getDataType());
        $this->assertSame(9, $sheet->getCell('C9')->getValue());
        $this->assertSame(4, $sheet->getCell('E9')->getValue());
        $this->assertSame(5, $sheet->getCell('G9')->getValue());
        $this->assertSame('44.44%', $sheet->getCell('J9')->getFormattedValue());
        $this->assertSame('A12', $sheet->getFreezePane());
        $this->assertSame('A11:J20', $sheet->getAutoFilter()->getRange());
        $this->assertTrue($sheet->getStyle('A11')->getFont()->getBold());
        $this->assertGreaterThan(20, $sheet->getColumnDimension('C')->getWidth());
        $workbook->disconnectWorksheets();
    }

    #[TestWith(['event_day', 3, 2])]
    #[TestWith(['participant_type', 3, 1])]
    #[TestWith(['attendance_status', 4, 4])]
    #[TestWith(['department_id', 3, 2])]
    #[TestWith(['search', 3, 1])]
    public function test_each_filter_changes_the_complete_csv_dataset_and_its_summary(string $filter, int $expected, int $present): void
    {
        $fixture = $this->fixture();
        $value = match ($filter) {
            'event_day' => $fixture['days'][0]->id, 'participant_type' => 'external', 'attendance_status' => 'present',
            'department_id' => $fixture['department']->id, 'search' => ['value' => 'Budi'],
        };
        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], 'csv', [$filter => $value]))->assertOk();
        $csv = $this->csv($response);
        $this->assertCount($expected, array_slice($csv, 11));
        $this->assertSame((string) $expected, $csv[8][2]);
        $this->assertSame((string) $present, $csv[8][4]);
        $this->assertSame((string) ($expected - $present), $csv[8][6]);
    }

    #[TestWith(['excel'])]
    #[TestWith(['csv'])]
    #[TestWith(['pdf'])]
    #[TestWith(['print'])]
    public function test_all_formats_share_combined_filters_and_summary(string $format): void
    {
        $fixture = $this->fixture();
        $filters = ['event_day' => $fixture['days'][0]->id, 'participant_type' => 'employee', 'attendance_status' => 'present',
            'department_id' => $fixture['department']->id, 'search' => ['value' => 'Alice']];
        $rendered = false;
        if ($format === 'pdf') {
            View::composer('events.partials.report-document', function (\Illuminate\View\View $view) use (&$rendered): void {
                $this->assertSame(['expected' => 1, 'present' => 1, 'not_present' => 0, 'percentage' => 100.0], $view->summary);
                $this->assertSame(['Alice Employee'], $view->rows->pluck('participant_name')->all());
                $rendered = true;
            });
        }
        $response = $this->actingAs($this->user('event-admin'))->getJson($this->url($fixture['event'], $format, $filters))->assertOk();
        if ($format === 'pdf') {
            $this->assertTrue($rendered);
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        } else {
            $rows = $this->exportedRows($response, $format);
            $this->assertCount(1, $rows);
            $this->assertSame('Alice Employee', $rows[0][2]);
            $this->assertSame('2027-01-15', $rows[0][6]);
        }
    }

    #[TestWith(['excel'])]
    #[TestWith(['csv'])]
    #[TestWith(['pdf'])]
    #[TestWith(['print'])]
    public function test_each_endpoint_rejects_guests_view_only_users_missing_scope_and_inactive_accounts(string $format): void
    {
        $event = Event::factory()->create();
        $url = $this->url($event, $format);
        $this->getJson($url)->assertUnauthorized();
        foreach (['monitoring', 'panitia'] as $role) {
            $this->actingAs($this->user($role))->getJson($url)->assertForbidden();
        }
        $exportOnly = User::factory()->create(['is_active' => true]);
        $exportOnly->givePermissionTo('reports.export');
        $this->actingAs($exportOnly)->getJson($url)->assertForbidden();
        $exportOnly->givePermissionTo('reports.view');
        $this->actingAs($exportOnly)->getJson($url)->assertForbidden();
        $inactive = $this->user();
        $inactive->is_active = false;
        $inactive->save();
        $this->actingAs($inactive)->getJson($url)->assertForbidden();
    }

    #[TestWith(['excel'])]
    #[TestWith(['csv'])]
    #[TestWith(['pdf'])]
    #[TestWith(['print'])]
    public function test_exports_validate_cross_event_days_sorting_and_event_uuid(string $format): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());
        $this->getJson($this->url($fixture['event'], $format, ['event_day' => $fixture['otherDay']->id]))->assertUnprocessable()->assertJsonValidationErrors('event_day');
        $this->getJson($this->url($fixture['event'], $format, ['order' => [['column' => 'name desc; drop table users', 'dir' => 'desc']]]))->assertUnprocessable();
        $this->getJson($this->url($fixture['event'], $format, ['event_id' => $fixture['otherDay']->event_id]))->assertUnprocessable();
        $this->getJson($this->url($fixture['event'], $format, ['participant_type' => 'external', 'department_id' => $fixture['department']->id]))->assertUnprocessable();
        $route = $format === 'print' ? 'events.reports.print' : 'events.reports.export.'.$format;
        $this->getJson(route($route, (string) Str::uuid7()))->assertNotFound();
        $this->getJson(route($route, $fixture['event']->id))->assertNotFound();
    }

    #[TestWith(['=1+1'])]
    #[TestWith(['+SUM(1,2)'])]
    #[TestWith(['-1+1'])]
    #[TestWith(['@SUM(1,2)'])]
    #[TestWith(["\t=1+1"])]
    #[TestWith(["\r=1+1"])]
    #[TestWith([" \u{FEFF}=1+1"])]
    #[TestWith(['Doe, "Jane"; café'])]
    public function test_csv_round_trips_quotes_unicode_and_neutralizes_formula_prefixes(string $name): void
    {
        $fixture = $this->fixture();
        $fixture['alice']->employee->update(['name' => $name]);
        $fixture['event']->update(['title' => '=1+1', 'location' => '+location']);
        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], 'csv'))->assertOk();
        $csv = $this->csv($response);
        $this->assertSame("'=1+1", $csv[2][1]);
        $this->assertSame("'+location", $csv[4][1]);
        $this->assertSame(str_starts_with($name, 'Doe') ? $name : "'".$name, $csv[11][2]);
    }

    #[TestWith(['excel'])]
    #[TestWith(['csv'])]
    #[TestWith(['pdf'])]
    #[TestWith(['print'])]
    public function test_empty_filtered_reports_are_valid_and_have_zero_summary(string $format): void
    {
        $fixture = $this->fixture();
        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], $format, ['search' => ['value' => 'Does not exist']]))->assertOk();
        if ($format === 'excel') {
            $workbook = $this->workbook($response);
            $sheet = $workbook->getActiveSheet();
            $this->assertSame(0, $sheet->getCell('C9')->getValue());
            $this->assertSame('0.00%', $sheet->getCell('J9')->getFormattedValue());
            $this->assertSame('Tidak ada data yang sesuai dengan filter.', $sheet->getCell('A12')->getValue());
            $workbook->disconnectWorksheets();
        } elseif ($format === 'pdf') {
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('%%EOF', $response->getContent());
        } elseif ($format === 'csv') {
            $csv = $this->csv($response);
            $this->assertSame('0', $csv[8][2]);
            $this->assertSame('0%', $csv[8][9]);
            $this->assertSame('Tidak ada data yang sesuai dengan filter.', $csv[11][0]);
        } else {
            $html = $response->streamedContent();
            $this->assertStringContainsString('Tidak ada data yang sesuai dengan filter.', $html);
            $this->assertStringContainsString('0,00%', $html);
        }
    }

    public function test_duplicate_attendance_copies_do_not_duplicate_exports_and_no_database_writes_occur(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($this->user());
        $scopes = Attendance::getAllGlobalScopes();
        Attendance::addGlobalScope('copies', fn (Builder $query): Builder => $query->crossJoinSub(DB::query()->selectRaw('1 as n')->unionAll(DB::query()->selectRaw('2 as n')), 'copies'));
        try {
            DB::enableQueryLog();
            $csv = $this->csv($this->getJson($this->url($fixture['event'], 'csv'))->assertOk());
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertCount(9, array_slice($csv, 11));
            $this->assertSame('4', $csv[8][4]);
            $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => preg_match('/^\s*(insert|update|delete|alter|create|drop)\b/i', $query['query']) === 1)));
        } finally {
            Attendance::setAllGlobalScopes($scopes);
            DB::disableQueryLog();
        }
        $this->assertDatabaseCount('attendances', 6);
    }

    public function test_pdf_is_real_landscape_multi_page_and_large_pdf_is_rejected_without_limiting_csv(): void
    {
        $fixture = $this->fixture();
        EventRegistration::factory()->for($fixture['event'])->invitedTo(...$fixture['days'])->count(30)->create();
        $this->actingAs($this->user());
        $response = $this->getJson($this->url($fixture['event'], 'pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+841\.\d+\s+595\.\d+\s*\]/', $response->getContent());
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
        config(['attendance_reports.pdf_max_rows' => 50]);
        $this->getJson($this->url($fixture['event'], 'pdf'))->assertUnprocessable()->assertJsonValidationErrors('export')->assertSee('Excel');
        $this->assertCount(99, $this->exportedRows($this->getJson($this->url($fixture['event'], 'csv'))->assertOk(), 'csv'));
    }

    public function test_csv_crosses_query_chunks_with_continuous_numbers_and_no_per_row_queries(): void
    {
        $fixture = $this->fixture();
        EventRegistration::factory()->for($fixture['event'])->invitedTo(...$fixture['days'])->count(170)->create();
        $this->actingAs($this->user());
        DB::enableQueryLog();
        try {
            $csv = $this->csv($this->getJson($this->url($fixture['event'], 'csv', ['start' => 500, 'length' => 1]))->assertOk());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $rows = array_slice($csv, 11);
        $this->assertCount(519, $rows);
        $this->assertSame('500', $rows[499][0]);
        $this->assertSame('501', $rows[500][0]);
        $this->assertSame('519', $rows[518][0]);
        $this->assertSame('519', $csv[8][2]);
        $this->assertSame('4', $csv[8][4]);
        $this->assertCount(519, array_unique(array_map(fn (array $row): string => $row[1].'|'.$row[6], $rows)));
        $this->assertLessThan(20, count($queries));
    }

    public function test_print_escapes_data_repeats_table_headers_and_contains_only_the_report(): void
    {
        $applicationName = 'Event & Attendance <Portal>';
        config(['app.name' => $applicationName]);
        $fixture = $this->fixture();
        $attack = '<script>alert("x")</script>';
        $fixture['alice']->employee->update(['name' => $attack]);
        $fixture['event']->update(['title' => $attack, 'location' => '<img src="https://evil.test/x">']);
        $response = $this->actingAs($this->user())->getJson($this->url($fixture['event'], 'print', ['search' => ['value' => $attack]]))->assertOk();
        $html = $response->streamedContent();
        $this->assertStringContainsString('<title>Laporan Kehadiran — '.e($attack).' - '.e($applicationName).'</title>', $html);
        $this->assertStringContainsString('<h1>PT Ari Utsaha Nusantara</h1>', $html);
        $this->assertStringContainsString(e($attack), $html);
        $this->assertStringNotContainsString($attack, $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<aside', $html);
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringContainsString('table-header-group', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('66,67%', $html);
        $this->assertCount(3, $this->htmlRows($html));
    }

    public function test_export_buttons_follow_permission_while_reports_remain_visible(): void
    {
        $event = Event::factory()->create();
        $this->actingAs($this->user('monitoring'))->get(route('events.show', $event))->assertOk()
            ->assertSee('data-attendance-report-table', false)->assertDontSee('data-report-export=', false);
        $response = $this->actingAs($this->user('event-admin'))->get(route('events.show', $event))->assertOk();
        foreach (['excel', 'csv', 'pdf', 'print'] as $format) {
            $route = $format === 'print' ? 'events.reports.print' : 'events.reports.export.'.$format;
            $response->assertSee('data-report-export="'.$format.'"', false)
                ->assertSee('data-url="'.route($route, $event, absolute: false).'"', false);
        }
    }

    /** @return array<int, array<int, string>> */
    private function exportedRows(TestResponse $response, string $format): array
    {
        if ($format === 'csv') {
            return array_map(fn (array $row): array => array_map(fn (?string $value): string => str_starts_with($value ?? '', "'") ? substr($value, 1) : ($value ?? ''), $row), array_slice($this->csv($response), 11));
        }
        if ($format === 'print') {
            return $this->htmlRows($response->streamedContent());
        }
        $workbook = $this->workbook($response);
        $rows = array_slice($workbook->getActiveSheet()->toArray(null, false, true, false), 11);
        $workbook->disconnectWorksheets();

        return array_map(fn (array $row): array => array_map(strval(...), $row), $rows);
    }

    private function workbook(TestResponse $response): Spreadsheet
    {
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    /** @return array<int, array<int, ?string>> */
    private function csv(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private function htmlRows(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $rows = [];
        foreach ($xpath->query('//tr[@data-report-row]') as $row) {
            $rows[] = array_map(fn (\DOMNode $cell): string => $cell->textContent, iterator_to_array($xpath->query('td', $row)));
        }

        return $rows;
    }

    private function user(string $role = 'super-admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $parameters */
    private function url(Event $event, string $format, array $parameters = []): string
    {
        return route($format === 'print' ? 'events.reports.print' : 'events.reports.export.'.$format, $event).($parameters ? '?'.http_build_query($parameters) : '');
    }

    /** @return array{event: Event, days: array<int, EventDay>, department: Department, alice: EventRegistration, otherDay: EventDay} */
    private function fixture(): array
    {
        $event = Event::factory()->published()->create(['title' => 'Raker Export', 'start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        $days = [];
        for ($day = 1; $day <= 3; $day++) {
            $days[] = EventDay::factory()->for($event)->create(['day_number' => $day, 'date' => '2027-01-'.(14 + $day)]);
        }
        $department = Department::factory()->create(['name' => 'Department Alpha']);
        $alice = EventRegistration::factory()->for($event)->invitedTo(...$days)->for(Employee::factory()->for($department)->create(['name' => 'Alice Employee']))->create();
        $budi = EventRegistration::factory()->for($event)->invitedTo(...$days)->for(Employee::factory()->create(['name' => 'Budi Employee']))->create();
        $external = EventRegistration::factory()->external()->for($event)->invitedTo(...$days)->create();
        $external->externalParticipant->update(['name' => 'Carla External']);
        $cancelled = EventRegistration::factory()->cancelled()->for($event)->invitedTo(...$days)->create();
        $cancelled->employee->update(['name' => 'Cancelled Participant']);
        $operator = User::factory()->create(['name' => 'Check-in Operator']);
        foreach ([[$alice, $days[0]], [$alice, $days[1]], [$budi, $days[1]], [$external, $days[0]], [$cancelled, $days[0]]] as [$registration, $day]) {
            Attendance::factory()->for($operator, 'operator')->create(['event_registration_id' => $registration->id, 'event_day_id' => $day->id, 'checked_in_at' => $day->date->copy()->setTime(8, 15)]);
        }
        $otherRegistration = EventRegistration::factory()->create();
        $otherRegistration->employee->update(['name' => 'Other Event Participant']);
        $otherDay = Attendance::factory()->create(['event_registration_id' => $otherRegistration->id])->eventDay;

        return compact('event', 'days', 'department', 'alice', 'otherDay');
    }
}
