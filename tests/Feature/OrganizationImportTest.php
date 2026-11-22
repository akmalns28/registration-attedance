<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrganizationImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'entity_code,entity_name,entity_status,department_code,department_name,department_status,division_code,division_name,division_status';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_batch_groups_repeated_codes_and_supports_optional_levels(): void
    {
        $this->signIn('departments.create');
        $id = $this->preview([
            '001,Head Office,Active,IT,Information Technology,Aktif,QA,Quality,1',
            '001,Head Office,Yes,it,Information Technology,True,SUP,Support,No',
            '001,Head Office,Aktif,OPS,Operations,Tidak Aktif,,,',
            'BR,Branch,1,IT,Information Technology,1,QA,Quality,1',
            'SOLO,Stand-alone,Active,,,,,,',
        ]);

        $this->get(route('organizations.import.show', $id))->assertOk()
            ->assertSee('Siap disimpan')->assertSee('Tidak Aktif');
        $this->assertDatabaseCount('entities', 0);
        $this->confirm($id);
        $this->confirm($id);

        $entity = Entity::query()->where('code', '001')->sole();
        $department = $entity->departments()->where('code', 'IT')->sole();
        $this->assertDatabaseCount('entities', 3);
        $this->assertDatabaseCount('departments', 3);
        $this->assertDatabaseCount('divisions', 3);
        $this->assertFalse($department->divisions()->where('code', 'SUP')->sole()->is_active);
        $this->assertFalse($entity->departments()->where('code', 'OPS')->sole()->is_active);
    }

    public function test_updates_reuse_scoped_ids_and_preserve_employee_links(): void
    {
        $entity = Entity::factory()->create(['code' => 'HQ', 'name' => 'Original']);
        $department = Department::factory()->for($entity)->create(['code' => 'IT']);
        $division = Division::factory()->for($department)->create(['code' => 'QA']);
        $employee = Employee::factory()->for($department)->for($division)->create();
        $legacy = Employee::factory()->create();
        $this->signIn('departments.update');

        $data = ['HQ,Head Office,Active,IT,Technology,Active,QA,Quality,Active'];
        $this->confirm($this->preview($data));
        $this->assertDatabaseHas('entities', ['id' => $entity->id, 'name' => 'Head Office']);
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Technology']);
        $this->assertDatabaseHas('divisions', ['id' => $division->id, 'name' => 'Quality']);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'department_id' => $department->id, 'division_id' => $division->id]);
        $this->assertDatabaseHas('employees', ['id' => $legacy->id, 'department_id' => $legacy->department_id]);
        $this->assertDatabaseCount('entities', 1);
        $this->assertDatabaseCount('departments', 2);
        $this->assertDatabaseCount('divisions', 1);

        $same = $this->preview($data);
        $this->get(route('organizations.import.show', $same))->assertOk()->assertSee('Tanpa perubahan');
        $this->postJson(route('organizations.import.confirm', $same), ['confirm' => 1])->assertUnprocessable();
        $new = $this->preview(['NEW,New office,Active']);
        $this->get(route('organizations.import.show', $new))->assertOk()->assertSee('Anda tidak memiliki izin');
    }

    public function test_any_invalid_row_blocks_the_entire_batch(): void
    {
        $entity = Entity::factory()->create(['code' => 'HQ', 'name' => 'Head Office']);
        $department = Department::factory()->for($entity)->create(['code' => 'IT', 'name' => 'Technology']);
        Employee::factory()->for($department)->create();
        $this->signIn('departments.create', 'departments.update');
        $id = $this->preview([
            'NEW,Valid office,Active',
            'HQ,Head Office,Active,IT,Technology,Inactive',
            'HQ,Conflicting name,Active',
            'B,Branch,Active,,,,QA,Orphan,Active',
        ]);
        $this->get(route('organizations.import.show', $id))->assertOk()
            ->assertSee('berbeda antarbaris')->assertSee('memerlukan induk')->assertSee('masih digunakan karyawan');
        $this->postJson(route('organizations.import.confirm', $id), ['confirm' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('entities', 1);
        $this->assertTrue($department->fresh()->is_active);
    }

    public function test_database_failure_rolls_back_earlier_rows_and_updates(): void
    {
        $entity = Entity::factory()->create(['code' => 'A', 'name' => 'Original']);
        $this->signIn('departments.create', 'departments.update');
        $id = $this->preview(['A,Changed,Active', 'Z,New,Active,IT,Technology,Active,QA,Quality,Active']);
        Division::creating(fn () => false);

        $this->confirm($id);
        $this->get(route('organizations.import.result', $id))->assertOk()->assertSee('Seluruh batch dibatalkan');
        $this->assertSame('Original', $entity->fresh()->name);
        $this->assertDatabaseCount('entities', 1);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_confirmation_rejects_a_hierarchy_that_changed_since_preview(): void
    {
        $entity = Entity::factory()->create(['code' => 'HQ', 'name' => 'Original']);
        $this->signIn('departments.create', 'departments.update');
        $id = $this->preview(['HQ,Proposed,Active', 'NEW,New office,Active']);
        $entity->update(['name' => 'Concurrent edit']);

        $this->confirm($id);
        $this->get(route('organizations.import.result', $id))->assertOk()->assertSee('berubah sejak preview');
        $this->assertSame('Concurrent edit', $entity->fresh()->name);
        $this->assertDatabaseCount('entities', 1);
    }

    public function test_excel_template_and_export_keep_codes_as_text(): void
    {
        $entity = Entity::factory()->create(['code' => '0001', 'name' => 'Head Office']);
        Department::factory()->for($entity)->create(['code' => '0010']);
        $this->signIn('departments.view', 'departments.export', 'departments.create');

        $template = $this->get(route('organizations.import.template'))->assertDownload('template-organizations.xlsx');
        $export = $this->get(route('organizations.export'))->assertDownload('organization-management.xlsx');
        $templateBook = IOFactory::load($template->baseResponse->getFile()->getPathname());
        $exportBook = IOFactory::load($export->baseResponse->getFile()->getPathname());
        $this->assertSame(explode(',', self::HEADER), $templateBook->getActiveSheet()->rangeToArray('A1:I1')[0]);
        $this->assertSame('@', $templateBook->getActiveSheet()->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame(DataType::TYPE_STRING, $exportBook->getActiveSheet()->getCell('A2')->getDataType());
        $this->assertSame('0001', $exportBook->getActiveSheet()->getCell('A2')->getValue());
        $this->assertSame('0010', $exportBook->getActiveSheet()->getCell('D2')->getValue());

        $spreadsheet = $templateBook->getActiveSheet();
        foreach (['A2' => '0020', 'B2' => 'Excel Office', 'C2' => 'Aktif'] as $cell => $value) {
            $spreadsheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
        $path = $template->baseResponse->getFile()->getPathname();
        IOFactory::createWriter($templateBook, 'Xlsx')->save($path);
        $file = UploadedFile::fake()->createWithContent('organizations.xlsx', File::get($path));
        $preview = $this->post(route('organizations.import.preview'), ['file' => $file])->assertRedirect()->assertSessionHasNoErrors();
        $this->confirm(basename($preview->headers->get('Location')));
        $this->assertDatabaseHas('entities', ['code' => '0020', 'name' => 'Excel Office']);
    }

    private function signIn(string ...$permissions): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
    }

    /** @param list<string> $rows */
    private function preview(array $rows): string
    {
        $file = UploadedFile::fake()->createWithContent('organizations.csv', self::HEADER."\n".implode("\n", $rows));
        $response = $this->post(route('organizations.import.preview'), ['file' => $file])->assertRedirect()
            ->assertSessionHasNoErrors();

        return basename($response->headers->get('Location'));
    }

    private function confirm(string $id): void
    {
        $this->post(route('organizations.import.confirm', $id), ['confirm' => 1])
            ->assertRedirect(route('organizations.import.result', $id));
    }
}
