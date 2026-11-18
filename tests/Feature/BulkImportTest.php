<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\BulkImportService;
use App\Services\BulkImportStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BulkImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
    }

    #[TestWith(['Xlsx'])]
    #[TestWith(['Xls'])]
    public function test_real_excel_preview_is_read_only_and_import_preserves_leading_zeros(string $format): void
    {
        $department = Department::factory()->create();
        $this->actingAs($this->admin());
        DB::enableQueryLog();
        $response = $this->post($this->url('employees', 'preview'), ['file' => $this->excel('employees', [['0000123', ' Éka   Putra ', $department->id, 'EKA@EXAMPLE.TEST', '0812-0034', 1]], $format)])->assertRedirect();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => preg_match('/^\s*(insert|update|delete|alter|create|drop)\b/i', $query['query']) === 1)));
        $this->assertDatabaseCount('employees', 0);
        $id = basename($response->headers->get('Location'));
        $this->get($this->url('employees', 'show', $id))->assertOk()->assertViewHas('snapshot', fn (array $s): bool => $s['summary']['create'] === 1 && $s['rows'][0]['data']['nip'] === '0000123');
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect($this->url('employees', 'result', $id));
        $this->assertDatabaseHas('employees', ['nip' => '0000123', 'name' => 'Éka Putra', 'email' => 'eka@example.test', 'phone' => '08120034', 'is_active' => true]);
        $this->get($this->url('employees', 'result', $id))->assertOk()->assertViewHas('snapshot', fn (array $s): bool => $s['result_summary'] === ['created' => 1, 'skipped' => 0, 'failed' => 0]);
    }

    public function test_employee_duplicates_existing_and_invalid_rows_are_separated_without_overwriting(): void
    {
        $department = Department::factory()->create();
        $existing = Employee::factory()->for($department)->create(['nip' => 'EXISTING', 'name' => 'Original Employee']);
        $this->actingAs($this->admin());
        $id = $this->preview('employees', [
            ['NEW1', 'New Employee', $department->id, 'valid@example.test', '000123', 1],
            ['dupe', 'Duplicate A', $department->id, null, null, 1], ['DUPE', 'Duplicate B', $department->id, null, null, 1],
            ['existing', 'Replacement', $department->id, null, null, 1], ['BAD-DEPT', 'Department', 999999, null, null, 1],
            ['BAD-EMAIL', 'Email', $department->id, 'invalid', null, 1], ['', 'Missing NIP', $department->id, null, null, 1], ['BAD-STATUS', 'Status', $department->id, null, null, 'yes'],
        ]);
        $this->get($this->url('employees', 'show', $id))->assertViewHas('snapshot', fn (array $s): bool => $s['summary'] === ['total' => 8, 'valid' => 2, 'invalid' => 4, 'existing' => 1, 'duplicate' => 2, 'create' => 1, 'skip' => 7]);
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('employees', 2);
        $this->assertSame('Original Employee', $existing->fresh()->name);
        $this->get($this->url('employees', 'result', $id))->assertViewHas('snapshot', fn (array $s): bool => $s['result_summary'] === ['created' => 1, 'skipped' => 3, 'failed' => 4]);
        $csv = $this->get($this->url('employees', 'errors', $id))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Departemen tidak tersedia', $csv);
    }

    public function test_external_import_conservatively_skips_contacts_and_possible_identity_matches(): void
    {
        $existing = ExternalParticipant::factory()->create(['name' => 'Existing Person', 'email' => 'exists@example.test', 'phone' => '0812-4455', 'organization' => 'Example']);
        $this->actingAs($this->admin());
        $id = $this->preview('external-participants', [
            [' New   Guest ', 'NEW@EXAMPLE.TEST', '0812-0000', ' Company ', ' Manager ', 1],
            ['Contact Match', 'EXISTS@EXAMPLE.TEST', null, 'Other', null, 1], ['Phone Match', null, '08124455', 'Other', null, 0],
            ['Existing Person', 'different@example.test', null, 'Example', null, 1], ['No Contact', null, null, null, null, 1], ['Bad Email', 'invalid', null, null, null, 1],
            ['Duplicate A', 'same@example.test', null, 'A', null, 1], ['Duplicate B', 'same@example.test', null, 'B', null, 1],
        ]);
        $this->post($this->url('external-participants', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('external_participants', 2);
        $this->assertDatabaseHas('external_participants', ['name' => 'New Guest', 'email' => 'new@example.test', 'phone' => '08120000', 'organization' => 'Company', 'position' => 'Manager']);
        $this->assertSame('exists@example.test', $existing->fresh()->email);
        $this->get($this->url('external-participants', 'result', $id))->assertViewHas('snapshot', fn (array $s): bool => $s['result_summary'] === ['created' => 1, 'skipped' => 5, 'failed' => 2]);
    }

    public function test_users_are_local_inactive_without_passwords_keycloak_calls_or_super_admin_escalation(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.test', 'name' => 'Original User']);
        $this->actingAs($this->admin());
        Socialite::shouldReceive('driver')->never();
        Http::fake();
        $id = $this->preview('users', [[' Local User ', ' LOCAL@EXAMPLE.TEST ', 1, 'panitia'], ['Existing Replacement', 'EXISTING@EXAMPLE.TEST', 0, 'monitoring'],
            ['Unknown Role', 'unknown@example.test', 0, 'not-a-role'], ['Escalation', 'escalation@example.test', 0, 'super-admin'], ['Missing Email', '', 0, 'panitia'],
            ['Duplicate A', 'duplicate@example.test', 0, 'panitia'], ['Duplicate B', 'DUPLICATE@EXAMPLE.TEST', 0, 'panitia']]);
        $this->get($this->url('users', 'show', $id))->assertOk()->assertSee('Keycloak belum diverifikasi')->assertViewHas('snapshot', fn (array $s): bool => $s['rows'][0]['data']['is_active'] === '0');
        $this->post($this->url('users', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $user = User::where('email', 'local@example.test')->firstOrFail();
        $this->assertFalse($user->is_active);
        $this->assertNull($user->password);
        $this->assertNull($user->keycloak_id);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('panitia'));
        $this->assertFalse($user->hasRole('super-admin'));
        $this->assertSame('Original User', $existing->fresh()->name);
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'duplicate@example.test']);
        Http::assertNothingSent();
    }

    #[TestWith(['employees'])]
    #[TestWith(['external-participants'])]
    #[TestWith(['users'])]
    public function test_every_endpoint_requires_authentication_and_its_management_permission(string $module): void
    {
        $id = (string) Str::uuid();
        $this->getJson($this->url($module, 'index'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active' => true]));
        foreach (['index', 'template', 'show', 'result', 'errors'] as $action) {
            $this->getJson($this->url($module, $action, in_array($action, ['index', 'template']) ? null : $id))->assertForbidden();
        }
        $this->postJson($this->url($module, 'preview'))->assertForbidden();
        $this->postJson($this->url($module, 'confirm', $id), ['confirm' => 1])->assertForbidden();
    }

    #[TestWith(['employees'])]
    #[TestWith(['external-participants'])]
    #[TestWith(['users'])]
    public function test_templates_use_supported_fields_and_text_formats(string $module): void
    {
        $this->actingAs($this->admin());
        $response = $this->get($this->url($module, 'template'))->assertOk()->assertDownload('template-'.$module.'.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame(BulkImportService::module($module)['columns'], $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0]);
            $this->assertSame('@', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
            $this->assertSame('A2', $sheet->getFreezePane());
            $book->disconnectWorksheets();
        } finally { unlink($path); }
        $this->get($this->url($module, 'index'))->assertOk()->assertSee('Validasi &amp; Preview', false);
    }

    #[TestWith(['broken.xlsx', 'not an Excel workbook'])]
    #[TestWith(['script.xls', '<html><script>alert(1)</script></html>'])]
    #[TestWith(['text.csv', 'name,email'])]
    public function test_corrupted_and_unsupported_files_are_rejected(string $name, string $content): void
    {
        $this->actingAs($this->admin())->postJson($this->url('employees', 'preview'), ['file' => UploadedFile::fake()->createWithContent($name, $content)])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_limits_and_unsupported_columns_are_rejected(): void
    {
        $department = Department::factory()->create();
        $this->actingAs($this->admin());
        $row = ['A', 'Name', $department->id, null, null, 1];
        config(['bulk_imports.max_upload_kb' => 1]);
        $this->postJson($this->url('employees', 'preview'), ['file' => $this->excel('employees', [$row])])->assertUnprocessable();
        config(['bulk_imports.max_upload_kb' => 5120, 'bulk_imports.max_rows' => 1]);
        $this->postJson($this->url('employees', 'preview'), ['file' => $this->excel('employees', [$row, $row])])->assertUnprocessable();
        config(['bulk_imports.max_rows' => 2000, 'bulk_imports.max_uncompressed_bytes' => 1]);
        $this->postJson($this->url('employees', 'preview'), ['file' => $this->excel('employees', [$row])])->assertUnprocessable();
        config(['bulk_imports.max_uncompressed_bytes' => 32 * 1024 * 1024]);
        $this->postJson($this->url('users', 'preview'), ['file' => $this->excel('users', [['Name', 'mail@example.test', 0, 'panitia']], headers: ['name', 'email', 'is_active', 'password'])])->assertUnprocessable();
    }

    public function test_formula_and_numeric_identifier_rows_fail_without_blocking_other_rows(): void
    {
        $department = Department::factory()->create();
        $this->actingAs($this->admin());
        $file = $this->excel('employees', [['=1+1', 'Formula', $department->id, null, null, 1], [123, 'Numeric NIP', $department->id, null, null, 1], ['GOOD', '<script>alert(1)</script>', $department->id, null, '001234', 1]], types: ['A2' => DataType::TYPE_FORMULA]);
        $response = $this->post($this->url('employees', 'preview'), ['file' => $file])->assertRedirect();
        $id = basename($response->headers->get('Location'));
        $this->get($this->url('employees', 'show', $id))->assertOk()->assertSee('formula')->assertSee('format Text')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseHas('employees', ['nip' => 'GOOD', 'phone' => '001234']);
    }

    public function test_confirmation_revalidates_references_and_existing_records(): void
    {
        $department = Department::factory()->create();
        $this->actingAs($this->admin());
        $id = $this->preview('employees', [['PENDING', 'Preview Name', $department->id, null, null, 1]]);
        $existing = Employee::factory()->for($department)->create(['nip' => 'PENDING', 'name' => 'Concurrent Creation']);
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertSame('Concurrent Creation', $existing->fresh()->name);
        $this->get($this->url('employees', 'result', $id))->assertViewHas('snapshot', fn (array $s): bool => $s['result_summary']['skipped'] === 1);
        $id = $this->preview('employees', [['NEW', 'Name', $department->id, null, null, 1]]);
        $department->update(['is_active' => false]);
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseMissing('employees', ['nip' => 'NEW']);
        $this->get($this->url('employees', 'result', $id))->assertViewHas('snapshot', fn (array $s): bool => $s['result_summary']['failed'] === 1);
    }

    public function test_confirmation_is_not_client_editable_and_duplicate_submissions_are_idempotent(): void
    {
        $department = Department::factory()->create();
        $this->actingAs($this->admin());
        $id = $this->preview('employees', [['001', 'Name', $department->id, null, null, 1]]);
        $this->postJson($this->url('employees', 'confirm', $id), ['confirm' => 1, 'rows' => [['nip' => 'FORGED']]])->assertUnprocessable();
        $this->assertDatabaseCount('employees', 0);
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('employees', 1);
        $this->get($this->url('employees', 'result', $id))->assertViewHas('snapshot', fn (array $s): bool => $s['result_summary']['created'] === 1);
    }

    public function test_snapshots_are_encrypted_private_module_scoped_and_expire(): void
    {
        $department = Department::factory()->create();
        $owner = $this->admin();
        $this->actingAs($owner);
        $id = $this->preview('employees', [['001', 'Private Name', $department->id, null, null, 1]]);
        $this->assertStringNotContainsString('Private Name', Storage::disk('local')->get('bulk-imports/'.$id.'.json'));
        $this->actingAs($this->admin())->get($this->url('employees', 'show', $id))->assertNotFound();
        $this->actingAs($owner)->get($this->url('users', 'show', $id))->assertNotFound();
        $this->travel(61)->minutes();
        $this->get($this->url('employees', 'show', $id))->assertStatus(410);
    }

    public function test_tampered_storage_and_interrupted_processing_cannot_be_replayed(): void
    {
        $department = Department::factory()->create();
        $owner = $this->admin();
        $this->actingAs($owner);
        $id = $this->preview('employees', [['001', 'Name', $department->id, null, null, 1]]);
        $store = app(BulkImportStore::class);
        $snapshot = $store->read($id, $owner, 'employees');
        $store->write($id, [...$snapshot, 'state' => 'processing']);
        $this->postJson($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertConflict();
        Storage::disk('local')->put('bulk-imports/'.$id.'.json', '{"owner":1,"rows":[]}');
        $this->getJson($this->url('employees', 'show', $id))->assertUnprocessable();
        $this->assertDatabaseCount('employees', 0);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super-admin');
        return $user;
    }

    private function url(string $module, string $action, ?string $id = null): string
    {
        return route(BulkImportService::module($module)['route'].'.import.'.$action, $id === null ? [] : ['import' => $id]);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function preview(string $module, array $rows): string
    {
        $response = $this->post($this->url($module, 'preview'), ['file' => $this->excel($module, $rows)])->assertRedirect();
        return basename($response->headers->get('Location'));
    }

    /** @param array<int, array<int, mixed>> $rows
     * @param array<int, string>|null $headers
     * @param array<string, string> $types
     */
    private function excel(string $module, array $rows, string $format = 'Xlsx', ?array $headers = null, array $types = []): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ([$headers ?? BulkImportService::module($module)['columns'], ...$rows] as $number => $row) {
            foreach ($row as $column => $value) {
                $cell = $sheet->getCell([$column + 1, $number + 1]);
                $type = $types[$cell->getCoordinate()] ?? (is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : ($value === null ? DataType::TYPE_NULL : DataType::TYPE_STRING));
                $cell->setValueExplicit($value, $type);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk-import-test-');
        try {
            IOFactory::createWriter($book, $format)->setPreCalculateFormulas(false)->save($path);
            return UploadedFile::fake()->createWithContent('import.'.strtolower($format), File::get($path));
        } finally { $book->disconnectWorksheets(); unlink($path); }
    }
}
