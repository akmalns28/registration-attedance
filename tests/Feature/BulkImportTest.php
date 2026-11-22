<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\BulkImportService;
use App\Services\BulkImportStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Spatie\Permission\Models\Role;
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
        $response = $this->post($this->url('employees', 'preview'), ['file' => $this->excel('employees', [['0000123', ' Éka   Putra ', $department->id, 'EKA@EXAMPLE.TEST', '0812-0034', 1]], $format, [' NIP ', 'name', 'department_id', 'email', 'phone', 'is_active'])])->assertRedirect();
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
            ['BAD-EMAIL', 'Email', $department->id, 'invalid', null, 1], ['', 'Missing NIP', $department->id, null, null, 1], ['BAD-STATUS', 'Status', $department->id, null, null, 'maybe'],
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

    public function test_employees_resolve_names_and_codes_and_reject_unavailable_or_ambiguous_departments(): void
    {
        $technology = Department::factory()->create(['code' => 'IT', 'name' => 'Teknologi Informasi']);
        $operations = Department::factory()->create(['code' => '00077', 'name' => 'Operasional']);
        $inactive = Department::factory()->create(['code' => 'CLOSED', 'name' => 'Departemen Lama', 'is_active' => false]);
        Department::factory()->create(['code' => 'SHARED', 'name' => 'Departemen Lain']);
        Department::factory()->create(['code' => (string) $technology->id, 'name' => 'Shared', 'is_active' => false]);
        $this->actingAs($this->admin());

        $id = $this->preview('employees', [
            ['BY-CODE', 'Kode', ' iT ', null, '08123456789', ' Active '],
            ['BY-NAME', 'Nama', ' OPERASIONAL ', null, '08123456788', 'Tidak Aktif'],
            ['PADDED-CODE', 'Nol di depan', '00077', null, null, 'aKtIf'],
            ['MISSING-REF', 'Kosong', '', null, null, 'Inactive'],
            ['UNKNOWN-REF', 'Tidak ditemukan', 'UNKNOWN', null, null, 'Inactive'],
            ['INACTIVE-NAME', 'Tidak aktif', 'Departemen Lama', null, null, 'Inactive'],
            ['INACTIVE-ID', 'ID tidak aktif', $inactive->id, null, null, 'Inactive'],
            ['AMBIGUOUS-NAME', 'Nama dan kode', ' shared ', null, null, 'Inactive'],
            ['AMBIGUOUS-ID', 'ID dan kode', $technology->id, null, null, 'Inactive'],
        ]);

        $preview = $this->get($this->url('employees', 'show', $id))->assertOk()
            ->assertSee('IT — Teknologi Informasi')->assertSee('Isian:  iT ')->assertSee('Tidak Aktif')
            ->assertSee('Departemen wajib diisi.')->assertSee('Departemen tidak tersedia.')
            ->assertSee('Departemen sudah tidak aktif.')->assertSee('Departemen memiliki lebih dari satu kecocokan.');
        $this->assertSame(['create', 'create', 'create', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid'], array_column($preview->viewData('snapshot')['rows'], 'status'));

        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('employees', 3);
        $this->assertDatabaseHas('employees', ['nip' => 'BY-CODE', 'department_id' => $technology->id, 'is_active' => true, 'phone' => '08123456789']);
        $this->assertDatabaseHas('employees', ['nip' => 'BY-NAME', 'department_id' => $operations->id, 'is_active' => false]);
        $this->assertDatabaseHas('employees', ['nip' => 'PADDED-CODE', 'department_id' => $operations->id, 'is_active' => true]);
        $this->assertDatabaseCount('departments', 5);
    }

    public function test_external_import_conservatively_skips_contacts_and_possible_identity_matches(): void
    {
        $existing = ExternalParticipant::factory()->create(['name' => 'Existing Person', 'email' => 'exists@example.test', 'phone' => '0812-4455', 'organization' => 'Example']);
        $this->actingAs($this->admin());
        $id = $this->preview('external-participants', [
            [' New   Guest ', 'NEW@EXAMPLE.TEST', '0812-0000', ' Company ', ' Manager ', 'Yes'],
            ['Contact Match', 'EXISTS@EXAMPLE.TEST', null, 'Other', null, 1], ['Phone Match', null, '08124455', 'Other', null, 'No'],
            ['Existing Person', 'different@example.test', null, 'Example', null, 1], ['No Contact', null, null, null, null, 1], ['Bad Email', 'invalid', null, null, null, 1],
            ['Duplicate A', 'same@example.test', null, 'A', null, 1], ['Duplicate B', 'same@example.test', null, 'B', null, 1],
        ]);
        $this->post($this->url('external-participants', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('external_participants', 2);
        $this->assertDatabaseHas('external_participants', ['name' => 'New Guest', 'email' => 'new@example.test', 'phone' => '08120000', 'organization' => 'Company', 'position' => 'Manager']);
        $this->assertSame('exists@example.test', $existing->fresh()->email);
        $this->get($this->url('external-participants', 'result', $id))->assertViewHas('snapshot', fn (array $s): bool => $s['result_summary'] === ['created' => 1, 'skipped' => 5, 'failed' => 2]);
    }

    public function test_external_import_normalizes_supported_statuses_and_rejects_unknown_or_missing_values(): void
    {
        $this->actingAs($this->admin());
        $statuses = [' Active ', 'Aktif', 'YeS', 'TRUE', 1, 'Inactive', ' Tidak  Aktif ', 'nO', 'FALSE', 0, 'enabled', ''];
        $rows = [];
        foreach ($statuses as $index => $status) {
            $rows[] = ['Guest '.$index, 'guest'.$index.'@example.test', null, null, null, $status];
        }

        $id = $this->preview('external-participants', $rows, ['name', 'email', 'phone', 'organization', 'position', 'is_active']);
        $preview = $this->get($this->url('external-participants', 'show', $id))->assertOk()
            ->assertSee('Status tidak dikenal.')->assertSee('Status peserta wajib diisi.');
        $this->assertSame(10, $preview->viewData('snapshot')['summary']['create']);

        $this->post($this->url('external-participants', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertSame(array_fill(0, 5, true) + array_fill(5, 5, false), ExternalParticipant::query()->orderBy('id')->pluck('is_active')->all());
    }

    public function test_users_are_local_inactive_without_passwords_keycloak_calls_or_super_admin_escalation(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.test', 'name' => 'Original User']);
        $this->actingAs($this->admin());
        Socialite::shouldReceive('driver')->never();
        Http::fake();
        $panitia = Role::findByName('panitia', 'web');
        $superAdmin = Role::findByName('super-admin', 'web');
        $nonWeb = Role::create(['name' => 'non-web-role', 'guard_name' => 'api']);
        Role::create(['name' => ' Monitoring ', 'guard_name' => 'web']);
        $id = $this->preview('users', [[' Local User ', ' LOCAL@EXAMPLE.TEST ', 'TRUE', ' PaNiTiA '], ['Existing Replacement', 'EXISTING@EXAMPLE.TEST', 0, 'panitia'],
            ['Unknown Role', 'unknown@example.test', 0, 'not-a-role'], ['Escalation', 'escalation@example.test', 0, 'SUPER-ADMIN'], ['Missing Email', '', 0, 'panitia'],
            ['Numeric Role', 'numeric@example.test', 'Tidak Aktif', $panitia->id], ['Numeric Escalation', 'numeric-escalation@example.test', 0, $superAdmin->id],
            ['Other Guard', 'guard@example.test', 0, $nonWeb->id], ['Ambiguous Role', 'ambiguous@example.test', 0, 'monitoring'], ['Missing Role', 'missing@example.test', 0, null],
            ['Duplicate A', 'duplicate@example.test', 0, 'panitia'], ['Duplicate B', 'DUPLICATE@EXAMPLE.TEST', 0, 'panitia']]);
        $this->get($this->url('users', 'show', $id))->assertOk()
            ->assertSee('Keycloak belum diverifikasi')->assertSee('Isian: TRUE')->assertSee('Status akun baru: Tidak Aktif')
            ->assertSee('Role super-admin tidak boleh diberikan melalui impor.')->assertSee('Role tidak tersedia.')
            ->assertSee('Role memiliki lebih dari satu kecocokan.')->assertSee('Role wajib diisi.')
            ->assertViewHas('snapshot', fn (array $s): bool => $s['rows'][0]['data']['is_active'] === false && $s['rows'][0]['display']['status'] === 'Aktif');
        DB::enableQueryLog();
        $this->post($this->url('users', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $roleLookups = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'select * from "roles" where'));
        DB::disableQueryLog();
        $this->assertCount(1, $roleLookups);
        $user = User::where('email', 'local@example.test')->firstOrFail();
        $this->assertFalse($user->is_active);
        $this->assertNull($user->password);
        $this->assertNull($user->keycloak_id);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('panitia'));
        $this->assertTrue(User::where('email', 'numeric@example.test')->firstOrFail()->hasRole('panitia'));
        $this->assertFalse($user->hasRole('super-admin'));
        $this->assertSame('Original User', $existing->fresh()->name);
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'numeric-escalation@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'guard@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'ambiguous@example.test']);
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

    #[TestWith(['employees', ['nip', 'name', 'entity', 'department', 'division', 'email', 'phone', 'status'], '0000123,Sample,HQ,IT,QA,,08123456789,Active'])]
    #[TestWith(['external-participants', ['name', 'email', 'phone', 'organization', 'position', 'status'], 'Sample,,08123456789,,,Inactive'])]
    #[TestWith(['users', ['name', 'email', 'status', 'role'], 'Sample,sample@example.test,Tidak Aktif,panitia'])]
    public function test_templates_use_supported_fields_and_text_formats(string $module, array $columns, string $csvRow): void
    {
        if ($module === 'employees') {
            $entity = Entity::factory()->create(['code' => 'HQ']);
            $department = Department::factory()->for($entity)->create(['code' => 'IT']);
            Division::factory()->for($department)->create(['code' => 'QA']);
        }
        $this->actingAs($this->admin());
        $response = $this->get($this->url($module, 'template'))->assertOk()->assertDownload('template-'.$module.'.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame($columns, $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0]);
            foreach ($columns as $index => $column) {
                $this->assertSame('@', $sheet->getStyle([$index + 1, 2])->getNumberFormat()->getFormatCode(), $column);
                $this->assertNotEmpty($sheet->getComment([$index + 1, 1])->getText()->getPlainText());
            }
            $this->assertSame('A2', $sheet->getFreezePane());
            $this->assertSame(array_fill(0, count($columns), ''), $sheet->rangeToArray('A2:'.$sheet->getHighestDataColumn().'2')[0]);
            foreach (explode(',', $csvRow) as $index => $value) {
                $sheet->setCellValueExplicit([$index + 1, 2], $value, DataType::TYPE_STRING);
            }
            IOFactory::createWriter($book, 'Xlsx')->save($path);
            $xlsx = UploadedFile::fake()->createWithContent('template-'.$module.'.xlsx', File::get($path));
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }

        $response = $this->post($this->url($module, 'preview'), ['file' => $xlsx])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Sample')->assertSee('Siap dibuat');

        $csv = $this->get($this->url($module, 'template').'?format=csv')->assertOk()->assertDownload('template-'.$module.'.csv')->streamedContent();
        $this->assertSame("\xEF\xBB\xBF".implode(',', $columns)."\r\n", $csv);
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv.$csvRow."\r\n");
        $response = $this->post($this->url($module, 'preview'), ['file' => $file])->assertRedirect();
        $id = basename($response->headers->get('Location'));
        $this->get($this->url($module, 'show', $id))->assertOk()->assertSee('Sample')->assertSee('Siap dibuat');
        $this->post($this->url($module, 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->get($this->url($module, 'result', $id))->assertOk()->assertSee('Tersimpan');

        $this->get($this->url($module, 'index'))->assertOk()->assertSee('Validasi &amp; Preview', false)->assertSee('Template CSV');
    }

    #[TestWith(['broken.xlsx', 'not an Excel workbook'])]
    #[TestWith(['script.xls', '<html><script>alert(1)</script></html>'])]
    #[TestWith(['invalid.csv', "nip,name,department,status\n001,Name,IT,\xFF"])]
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
        $this->postJson($this->url('employees', 'preview'), ['file' => $this->excel('employees', [], headers: ['nip', 'name', 'department', 'department_id', 'status'])])
            ->assertUnprocessable()->assertJsonValidationErrors(['file' => 'Judul kolom tidak valid atau berulang.']);
    }

    public function test_csv_preserves_leading_zeros_and_reports_invalid_records_with_valid_records_in_the_same_file(): void
    {
        $department = Department::factory()->create(['code' => '00105']);
        $this->actingAs($this->admin());
        $file = UploadedFile::fake()->createWithContent('import.csv', "nip;name;department;phone;status\r\n"
            ."0000123;\"Putra; Akmal\";00105;08120034;Aktif\r\n"
            ."=1+1;Formula;00105;08120035;Aktif\r\n"
            ."0000125;Too many values;00105;08120036;Inactive;extra\r\n");
        $response = $this->post($this->url('employees', 'preview'), ['file' => $file])->assertRedirect();
        $id = basename($response->headers->get('Location'));

        $this->get($this->url('employees', 'show', $id))->assertOk()->assertSee('Putra; Akmal')
            ->assertSee('formula tidak diizinkan')->assertSee('Jumlah nilai melebihi jumlah kolom');
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseHas('employees', ['nip' => '0000123', 'phone' => '08120034', 'department_id' => $department->id]);
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

    public function test_confirmation_does_not_attach_a_reference_that_changed_to_a_different_record(): void
    {
        $original = Department::factory()->create(['code' => 'IT']);
        $this->actingAs($this->admin());
        $id = $this->preview('employees', [['REBIND', 'Name', 'IT', null, null, 'Aktif']]);
        $original->update(['code' => 'OLD-IT']);
        Department::factory()->create(['code' => 'IT']);

        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();

        $this->assertDatabaseMissing('employees', ['nip' => 'REBIND']);
        $this->get($this->url('employees', 'result', $id))->assertOk()->assertSee('Referensi berubah sejak preview.');
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

    public function test_employee_import_scopes_codes_and_names_to_each_selected_parent(): void
    {
        $this->actingAs($this->admin());
        $hq = Entity::factory()->create(['code' => 'HQ', 'name' => 'Head Office']);
        $branch = Entity::factory()->create(['code' => 'BR', 'name' => 'Branch']);
        $hqOps = Department::factory()->for($hq)->create(['code' => 'OPS', 'name' => 'Operations']);
        $branchOps = Department::factory()->for($branch)->create(['code' => 'OPS', 'name' => 'Operations']);
        Department::factory()->for($hq)->create(['code' => 'IT', 'name' => 'Support']);
        Department::factory()->for($hq)->create(['code' => 'FAC', 'name' => 'Support']);
        Department::factory()->for($branch)->create(['code' => 'BR-ONLY']);
        $hqQuality = Division::factory()->for($hqOps)->create(['code' => 'QA', 'name' => 'Quality']);
        Division::factory()->for($hqOps)->create(['code' => 'Q2', 'name' => 'Quality']);
        $branchQuality = Division::factory()->for($branchOps)->create(['code' => 'QA', 'name' => 'Quality']);
        Division::factory()->for($branchOps)->create(['code' => 'BR-QA']);

        $headers = ['nip', 'name', 'entity', 'department', 'division', 'status'];
        $id = $this->preview('employees', [
            ['HQ-OK', 'HQ Employee', ' hq ', 'ops', 'qa', 'Aktif'],
            ['BR-OK', 'Branch Employee', 'Branch', 'Operations', 'Quality', 'Aktif'],
            ['NO-DIV', 'Optional Division', 'Head Office', 'OPS — Operations', null, 'Aktif'],
            ['BAD-ENT', 'Unknown Entity', 'UNKNOWN', 'OPS', null, 'Aktif'],
            ['BAD-DEPT', 'Wrong Entity', 'HQ', 'BR-ONLY', null, 'Aktif'],
            ['BAD-DIV', 'Wrong Dept', 'HQ', 'OPS', 'BR-QA', 'Aktif'],
            ['AMB-DEPT', 'Ambiguous Department', 'HQ', 'Support', null, 'Aktif'],
            ['AMB-DIV', 'Ambiguous Division', 'HQ', 'OPS', 'Quality', 'Aktif'],
            ['NO-ENT', 'Missing Entity', null, 'OPS', null, 'Aktif'],
        ], $headers);

        $this->get($this->url('employees', 'show', $id))->assertOk()
            ->assertSee('HQ — Head Office')->assertSee('QA — Quality')->assertSee('Entitas tidak tersedia.')
            ->assertSee('Departemen memiliki lebih dari satu kecocokan.')->assertSee('Divisi memiliki lebih dari satu kecocokan.')
            ->assertSee('Divisi tidak tersedia.')->assertViewHas('snapshot', fn (array $snapshot): bool => $snapshot['summary']['create'] === 3);
        $this->post($this->url('employees', 'confirm', $id), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('employees', 3);
        $this->assertDatabaseHas('employees', ['nip' => 'HQ-OK', 'department_id' => $hqOps->id, 'division_id' => $hqQuality->id]);
        $this->assertDatabaseHas('employees', ['nip' => 'BR-OK', 'department_id' => $branchOps->id, 'division_id' => $branchQuality->id]);
        $this->assertDatabaseHas('employees', ['nip' => 'NO-DIV', 'department_id' => $hqOps->id, 'division_id' => null]);
    }

    public function test_employee_import_rechecks_the_full_hierarchy_at_confirmation(): void
    {
        $this->actingAs($this->admin());
        $department = Department::factory()->for(Entity::factory()->create(['code' => 'HQ']))->create(['code' => 'OPS']);
        $division = Division::factory()->for($department)->create(['code' => 'QA']);
        $pending = $this->preview('employees', [
            ['PENDING', 'Preview Name', 'HQ', 'OPS', 'QA', 'Aktif'],
        ], ['nip', 'name', 'entity', 'department', 'division', 'status']);
        $division->update(['code' => 'OLD-QA']);
        Division::factory()->for($department)->create(['code' => 'QA']);

        $this->post($this->url('employees', 'confirm', $pending), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseMissing('employees', ['nip' => 'PENDING']);
        $this->get($this->url('employees', 'result', $pending))->assertOk()->assertSee('Referensi berubah sejak preview.');
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

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>|null  $headers
     */
    private function preview(string $module, array $rows, ?array $headers = null): string
    {
        $response = $this->post($this->url($module, 'preview'), ['file' => $this->excel($module, $rows, headers: $headers)])->assertRedirect();

        return basename($response->headers->get('Location'));
    }

    /** @param array<int, array<int, mixed>> $rows
     * @param  array<int, string>|null  $headers
     * @param  array<string, string>  $types
     */
    private function excel(string $module, array $rows, string $format = 'Xlsx', ?array $headers = null, array $types = []): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $headers ??= $module === 'employees' ? ['nip', 'name', 'department', 'email', 'phone', 'status'] : BulkImportService::module($module)['columns'];
        foreach ([$headers, ...$rows] as $number => $row) {
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
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
