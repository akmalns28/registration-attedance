<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_form_creates_and_displays_a_normalized_hierarchy(): void
    {
        $this->actingAs($this->manager());
        $this->get(route('organizations.create'))->assertOk()->assertSee('Master Data')
            ->assertSee('Organization Management')->assertSee('Tambah Organisasi')->assertSee('Tambah Divisi');

        $this->post(route('organizations.store'), $this->fields(' root ', 'Head Office', [
            'departments' => [
                $this->fields(' ops ', 'Operations', ['divisions' => [$this->fields(' qa ', 'Quality')]]),
                $this->fields('it', 'Technology', ['divisions' => [$this->fields('qa', 'Quality', ['is_active' => 0])]]),
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('organizations.index'));

        $entity = Entity::query()->sole();
        $this->assertSame('ROOT', $entity->code);
        $operations = $entity->departments()->where('code', 'OPS')->sole();
        $this->assertSame('QA', $operations->divisions()->sole()->code);
        $this->assertFalse($entity->departments()->where('code', 'IT')->sole()->divisions()->sole()->is_active);
        $this->get(route('organizations.index'))->assertOk()->assertSee('ROOT')->assertSee('OPS — Operations')
            ->assertSee('Quality')->assertSee('Tidak Aktif');
        $this->get(route('organizations.edit', $entity))->assertOk()
            ->assertSee('name="departments[0][divisions][0][code]"', false)->assertSee('value="QA"', false);

        $this->post(route('organizations.store'), $this->fields('BRANCH', 'Branch', [
            'departments' => [$this->fields('ops', 'Operations')],
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, Department::query()->where('code', 'OPS')->count());
    }

    public function test_duplicate_codes_are_rejected_at_each_scope_without_partial_writes(): void
    {
        Entity::factory()->create(['code' => 'EXISTING']);
        $this->actingAs($this->manager());

        $this->postJson(route('organizations.store'), $this->fields(' existing ', 'Duplicate'))
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->post(route('organizations.store'), $this->fields('NEW', 'New Entity', [
            'departments' => [
                $this->fields('ops', 'Operations'),
                $this->fields(' OPS ', 'Operations II', [
                    'divisions' => [$this->fields('qa', 'Quality'), $this->fields(' QA ', 'Quality II')],
                ]),
            ],
        ]))->assertSessionHasErrors(['departments.1.code', 'departments.1.divisions.1.code']);

        $this->assertDatabaseCount('entities', 1);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('divisions', 0);
    }

    #[TestWith(['entity'])]
    #[TestWith(['division'])]
    public function test_database_enforces_code_uniqueness(string $level): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        if ($level === 'entity') {
            Entity::factory()->count(2)->create(['code' => ' duplicate ']);
        } else {
            Division::factory()->for(Department::factory())->count(2)->create(['code' => ' duplicate ']);
        }
    }

    public function test_updates_reject_codes_and_ids_belonging_to_siblings_or_other_parents(): void
    {
        $this->actingAs($this->manager());
        $entity = Entity::factory()->create();
        $department = Department::factory()->for($entity)->create(['code' => 'OPS']);
        $sibling = Department::factory()->for($entity)->create(['code' => 'IT']);
        $foreignDepartment = Department::factory()->for(Entity::factory())->create();
        $division = Division::factory()->for($department)->create(['code' => 'QA']);
        Division::factory()->for($department)->create(['code' => 'ENG']);
        $foreignDivision = Division::factory()->for($sibling)->create();
        $url = route('organizations.update', $entity);

        $this->put($url, $this->fields($entity->code, 'Changed', [
            'departments' => [
                $this->fields(' it ', 'Conflict', ['id' => $department->id, 'divisions' => [
                    $this->fields(' eng ', 'Conflict', ['id' => $division->id]),
                    $this->fields('OTHER', 'Wrong Parent', ['id' => $foreignDivision->id]),
                ]]),
                $this->fields('OTHER', 'Wrong Parent', ['id' => $foreignDepartment->id]),
            ],
        ]))->assertSessionHasErrors(['departments.0.code', 'departments.0.divisions.0.code', 'departments.0.divisions.1.id', 'departments.1.id']);
        $this->assertSame($entity->name, $entity->fresh()->name);
        $this->assertSame('OPS', $department->fresh()->code);
        $this->assertSame('QA', $division->fresh()->code);
    }

    public function test_a_database_conflict_rolls_back_the_entire_hierarchy(): void
    {
        $this->actingAs($this->manager());
        Division::creating(function (Division $division): void {
            DB::table('divisions')->insert($division->only(['department_id', 'code', 'name', 'is_active']));
        });

        $this->post(route('organizations.store'), $this->fields('ROOT', 'Head Office', [
            'departments' => [$this->fields('OPS', 'Operations', ['divisions' => [$this->fields('QA', 'Quality')]])],
        ]))->assertSessionHasErrors('departments.0.divisions.0.code');

        $this->assertDatabaseCount('entities', 0);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_entity_fields_are_required_and_an_entity_can_be_saved_without_departments(): void
    {
        $this->actingAs($this->manager());
        $url = route('organizations.store');
        $entity = $this->fields(' HQ ', 'Head Office');
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name', 'is_active']);
        $this->assertDatabaseCount('entities', 0);
        $this->post($url, $entity)->assertRedirect(route('organizations.index'));
        $this->assertDatabaseHas('entities', ['code' => 'HQ']);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_form_preserves_input_and_shows_inline_errors_and_can_save_without_divisions(): void
    {
        $this->actingAs($this->manager());
        $form = route('organizations.create');
        $url = route('organizations.store');
        $draft = [...$this->fields('HQ', 'Head Office'), '_organization_form' => '1', 'departments' => [
            4 => $this->fields('OPS', 'Operations', ['is_active' => 0]),
        ]];
        $invalid = $draft;
        $invalid['departments'][4]['divisions'][9] = $this->fields('QA', '');
        $this->from($form)->post($url, $invalid)->assertRedirect($form)->assertSessionHasErrors('departments.4.divisions.9.name');
        $this->withCookie(config('session.cookie'), session()->getId())->get($form)
            ->assertOk()->assertSee('Isi nama divisi.')->assertSee('aria-invalid="true"', false)
            ->assertSee('value="Head Office"', false)->assertSee('value="Operations"', false)
            ->assertSee('name="departments[4][divisions][9][code]"', false)->assertSee('value="QA"', false);
        $this->assertDatabaseCount('entities', 0);

        $this->post($url, $draft)->assertRedirect(route('organizations.index'))->assertSessionHas('success');
        $this->assertDatabaseHas('departments', ['code' => 'OPS', 'is_active' => false]);
        $this->assertDatabaseCount('divisions', 0);
    }

    public function test_edit_form_updates_existing_ids_and_keeps_omitted_relations(): void
    {
        $this->actingAs($this->manager());
        $entity = Entity::factory()->create(['name' => 'Original']);
        $department = Department::factory()->for($entity)->create();
        $division = Division::factory()->for($department)->create();
        $omittedDepartment = Department::factory()->for($entity)->create();
        $omittedDivision = Division::factory()->for($department)->create();
        $employee = Employee::factory()->for($department)->for($division)->create();
        $url = route('organizations.update', $entity);
        $draft = $this->fields($entity->code, 'Edited', ['departments' => [
            $this->fields('NEW-DPT', 'Edited Department', ['id' => $department->id, 'divisions' => [
                $this->fields('NEW-DIV', 'Edited Division', ['id' => $division->id, 'is_active' => 0]),
            ]]),
        ]]);

        $this->put($url, $draft)->assertRedirect(route('organizations.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('entities', ['id' => $entity->id, 'name' => 'Edited']);
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'entity_id' => $entity->id, 'code' => 'NEW-DPT']);
        $this->assertDatabaseHas('divisions', ['id' => $division->id, 'department_id' => $department->id, 'code' => 'NEW-DIV', 'is_active' => false]);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'department_id' => $department->id, 'division_id' => $division->id]);
        $this->assertSame($entity->id, $omittedDepartment->fresh()->entity_id);
        $this->assertSame($department->id, $omittedDivision->fresh()->department_id);
    }

    public function test_index_search_actions_and_browser_fixtures(): void
    {
        $directory = getenv('ORGANIZATION_BROWSER_ARTIFACT_DIR');
        if (is_string($directory) && is_dir($directory)) {
            Vite::useHotFile($directory.'/vite.hot');
        }

        $entity = Entity::factory()->create(['code' => 'HQ', 'name' => 'Main Office']);
        $department = Department::factory()->for($entity)->create(['code' => 'OPS', 'name' => 'Operations']);
        $division = Division::factory()->for($department)->create(['code' => 'QA', 'name' => 'Quality']);
        Employee::factory()->for($department)->for($division)->create();
        foreach ([
            ['ZEB', 'Zebra Department', 'ZEN', 'Zenith Division with a long name for layout coverage'],
            ['FIN', 'Finance', 'PAY', 'Payroll'],
            ['ENG', 'Engineering', 'DEV', 'Development'],
        ] as [$code, $name, $divisionCode, $divisionName]) {
            $child = Department::factory()->for($entity)->create(['code' => $code, 'name' => $name]);
            Division::factory()->for($child)->create(['code' => $divisionCode, 'name' => $divisionName]);
        }
        Employee::factory()->for($department)->create();
        $branch = Entity::factory()->create(['code' => 'BR', 'name' => 'Remote Office']);
        $support = Department::factory()->for($branch)->create(['code' => 'SUP', 'name' => 'Support']);
        Division::factory()->for($support)->create(['code' => 'SRV', 'name' => 'Services']);
        $empty = Entity::factory()->create(['code' => 'EMPTY', 'name' => 'Unassigned Office']);
        $legacy = Department::factory()->create(['code' => 'LEG', 'name' => 'Legacy Department']);
        Employee::factory()->for($legacy)->create();
        Entity::factory()->count(10)->sequence(fn ($sequence) => [
            'code' => 'SITE-'.$sequence->index, 'name' => 'Site '.$sequence->index,
        ])->create();
        $this->actingAs($this->manager());

        foreach (['hq', 'Main Office', 'ZEB', 'Zebra Department', 'ZEN', 'Zenith Division'] as $search) {
            $this->get(route('organizations.index', ['q' => $search]))->assertOk()->assertSee('Main Office')
                ->assertDontSee('Remote Office')->assertDontSee('Legacy Department');
        }
        $index = $this->get(route('organizations.index'))->assertOk()
            ->assertSee(route('organizations.edit', $entity), false)
            ->assertSee('Import Organization')->assertSee('data-export="true"', false)->assertSee('Legacy Department');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$index->getContent());
        $xpath = new \DOMXPath($document);
        $table = '//table[@id="organizationsTable"]/tbody';
        $this->assertSame(13, $xpath->query($table.'/tr')->length);
        foreach ([[$entity, 3, 4, 2, 2], [$branch, 1, 1, 0, 0], [$empty, 0, 0, 0, 0]] as [$record, $badges, $total, $employees, $more]) {
            $row = $table.'/tr[@data-organization-entity="'.$record->id.'"]';
            foreach ([3, 4] as $column) {
                $this->assertSame($badges, $xpath->query($row.'/td['.$column.']//span[contains(@class,"badge")]')->length);
                $this->assertSame((string) $total, $xpath->evaluate('string('.$row.'/td['.$column.']/@data-order)'));
                if ($total === 0) {
                    $this->assertSame('—', trim($xpath->evaluate('string('.$row.'/td['.$column.'])')));
                }
            }
            $this->assertSame((string) $employees, trim($xpath->evaluate('string('.$row.'/td[5])')));
            $this->assertSame($more, $xpath->query($row.'//button[@data-organization-list!="all"]')->length);
        }
        $this->assertSame(1, $xpath->query('//*[@id="organization-related-modal"]')->length);
        $form = $this->get(route('organizations.create'))->assertOk()
            ->assertSee('Struktur Organisasi')->assertSee('Simpan Organisasi')->assertSee('data-organization-review', false);

        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.'/organizations.html', $index->getContent());
            file_put_contents($directory.'/organization-form.html', $form->getContent());
            file_put_contents($directory.'/employee-form.html', $this->get(route('employees.create'))->assertOk()->getContent());
        }
    }

    public function test_view_only_permissions_hide_mutation_and_export_actions(): void
    {
        Entity::factory()->create();
        $this->actingAs($this->manager(['departments.view']))->get(route('organizations.index'))->assertOk()
            ->assertSee('Lihat Organisasi')->assertSee('data-export="false"', false)
            ->assertDontSee('Tambah Organisasi')->assertDontSee('Import Organization')->assertDontSee('Edit Organisasi');
        $this->get(route('organizations.import.index'))->assertForbidden();
        $this->get(route('organizations.export'))->assertForbidden();
    }

    public function test_legacy_departments_can_be_attached_and_codes_backfilled_in_place(): void
    {
        $this->actingAs($this->manager());
        $entity = Entity::factory()->create(['code' => null]);
        $legacy = Department::factory()->create(['code' => null, 'description' => 'Keep this']);
        $omitted = Department::factory()->for($entity)->create();
        $division = Division::factory()->for($legacy)->create(['code' => null]);
        $unchanged = Division::factory()->for($legacy)->create(['code' => 'SUPPORT']);
        $employee = Employee::factory()->for($legacy)->for($division)->create();
        $url = route('organizations.update', $entity);

        $this->get(route('departments.edit', $legacy))->assertOk()->assertSee('Departemen lama');
        $this->get(route('organizations.edit', $entity))->assertOk()->assertSee($legacy->name);
        $this->put($url, $this->fields(null, $entity->name, [
            'departments' => [$this->fields(null, $legacy->name, ['id' => $legacy->id])],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($entity->id, $legacy->fresh()->entity_id);
        $this->assertNull($legacy->fresh()->code);
        $this->assertNull($division->fresh()->code);

        $this->put($url, $this->fields(' root ', $entity->name, [
            'departments' => [$this->fields(' ops ', $legacy->name, [
                'id' => $legacy->id, 'divisions' => [$this->fields(' qa ', $division->name, ['id' => $division->id])],
            ])],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('entities', ['id' => $entity->id, 'code' => 'ROOT']);
        $this->assertDatabaseHas('departments', ['id' => $legacy->id, 'entity_id' => $entity->id, 'code' => 'OPS', 'description' => 'Keep this']);
        $this->assertDatabaseHas('divisions', ['id' => $division->id, 'department_id' => $legacy->id, 'code' => 'QA']);
        $this->assertSame('SUPPORT', $unchanged->fresh()->code);
        $this->assertSame($entity->id, $omitted->fresh()->entity_id);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'department_id' => $legacy->id, 'division_id' => $division->id]);
    }

    public function test_create_and_update_keep_their_separate_permissions(): void
    {
        $entity = Entity::factory()->create();
        $this->actingAs($this->manager(['departments.update']));

        $this->get(route('organizations.index'))->assertForbidden();
        $this->get(route('organizations.create'))->assertForbidden();
        $this->get(route('organizations.edit', $entity))->assertOk()->assertDontSee('Tambah Departemen');
        $this->post(route('organizations.store'), $this->fields('NEW', 'New'))->assertForbidden();
        $this->put(route('organizations.update', $entity), $this->fields($entity->code, $entity->name, [
            'departments' => [$this->fields('NEW', 'New')],
        ]))->assertSessionHasErrors('departments.0.name');
    }

    public function test_additive_migration_preserves_preexisting_records_and_references(): void
    {
        $defaultConnection = DB::getDefaultConnection();
        config(['database.connections.organization_upgrade' => config('database.connections.sqlite')]);
        DB::setDefaultConnection('organization_upgrade');

        try {
            $migrations = database_path('migrations');
            (require $migrations.'/2026_11_11_153202_create_departments_table.php')->up();
            (require $migrations.'/2026_11_11_170653_create_employees_table.php')->up();
            DB::table('departments')->insert(['id' => 42, 'code' => 'LEGACY', 'name' => 'Existing Department']);
            DB::table('employees')->insert(['id' => 21, 'nik' => 'EXISTING', 'name' => 'Existing Employee', 'department_id' => 42]);

            (require $migrations.'/2026_11_21_202827_add_organization_hierarchy.php')->up();

            $this->assertDatabaseHas('departments', ['id' => 42, 'code' => 'LEGACY', 'name' => 'Existing Department', 'entity_id' => null]);
            $this->assertDatabaseHas('employees', ['id' => 21, 'department_id' => 42, 'division_id' => null, 'nik' => 'EXISTING']);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        } finally {
            DB::setDefaultConnection($defaultConnection);
            DB::purge('organization_upgrade');
        }
    }

    /** @param list<string> $permissions */
    private function manager(array $permissions = ['dashboard.view', 'departments.view', 'departments.create', 'departments.update', 'departments.export', 'employees.manage']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    /** @param array<string, mixed> $extra */
    private function fields(?string $code, string $name, array $extra = []): array
    {
        return [...['code' => $code, 'name' => $name, 'is_active' => 1], ...$extra];
    }
}
