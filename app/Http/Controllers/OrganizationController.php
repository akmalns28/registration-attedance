<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveOrganizationRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\Entity;
use App\Services\ImportSpreadsheetTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $search = $this->searchTerm($request);
        $entities = $this->entities($search)->get();
        $legacyDepartments = $this->legacyDepartments($search)->paginate(20, pageName: 'legacy_page')->withQueryString();

        return view('organizations.index', compact('entities', 'legacyDepartments', 'search'));
    }

    public function export(Request $request, Excel $excel): BinaryFileResponse
    {
        $search = $this->searchTerm($request);
        $rows = [];
        foreach ($this->entities($search)->reorder('id')->lazy(100) as $entity) {
            if ($entity->departments->isEmpty()) {
                $rows[] = $this->exportRow($entity);
            }
            foreach ($entity->departments as $department) {
                $this->appendDepartmentRows($rows, $entity, $department);
            }
        }
        foreach ($this->legacyDepartments($search)->reorder('id')->lazy(100) as $department) {
            $this->appendDepartmentRows($rows, null, $department);
        }

        return $excel->download(new ImportSpreadsheetTemplate('organizations', $rows), 'organization-management.xlsx');
    }

    /** @param list<list<string>> $rows */
    private function appendDepartmentRows(array &$rows, ?Entity $entity, Department $department): void
    {
        if ($department->divisions->isEmpty()) {
            $rows[] = $this->exportRow($entity, $department);
        }
        foreach ($department->divisions as $division) {
            $rows[] = $this->exportRow($entity, $department, $division);
        }
    }

    /** @return list<string> */
    private function exportRow(?Entity $entity, ?Department $department = null, ?Division $division = null): array
    {
        $fields = fn (Entity|Department|Division|null $record): array => [
            $record?->code ?? '', $record?->name ?? '', $record ? ($record->is_active ? 'Active' : 'Inactive') : '',
        ];

        return [...$fields($entity), ...$fields($department), ...$fields($division)];
    }

    private function searchTerm(Request $request): string
    {
        return trim($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');
    }

    private function entities(string $search): Builder
    {
        $query = Entity::query()->withCount(['departments', 'divisions', 'employees'])
            ->with(['departments' => fn ($departments) => $departments->orderBy('name'),
                'departments.divisions' => fn ($divisions) => $divisions->orderBy('name')])
            ->orderBy('name')->orderBy('id');
        if ($search !== '') {
            $match = $this->matching($search);
            $query->where(fn (Builder $entity) => $entity->where($match)
                ->orWhereHas('departments', $match)->orWhereHas('divisions', $match));
        }

        return $query;
    }

    private function legacyDepartments(string $search): Builder
    {
        $query = Department::query()->whereNull('entity_id')->withCount('employees')
            ->with(['divisions' => fn ($divisions) => $divisions->orderBy('name')])
            ->orderBy('name')->orderBy('id');
        if ($search !== '') {
            $match = $this->matching($search);
            $query->where(fn (Builder $department) => $department->where($match)->orWhereHas('divisions', $match));
        }

        return $query;
    }

    private function matching(string $search): \Closure
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return fn (Builder $query): Builder => $query->whereRaw('lower('.$query->qualifyColumn('code').") like lower(?) escape '!'", [$pattern])
            ->orWhereRaw('lower('.$query->qualifyColumn('name').") like lower(?) escape '!'", [$pattern]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(SaveOrganizationRequest $request): RedirectResponse
    {
        $this->save($request->validated(), null);

        return redirect()->route('organizations.index')->with('success', 'Organisasi berhasil ditambahkan.');
    }

    public function edit(Entity $organization): View
    {
        return $this->form($organization);
    }

    public function update(SaveOrganizationRequest $request, Entity $organization): RedirectResponse
    {
        $this->save($request->validated(), $organization);

        return redirect()->route('organizations.index')->with('success', 'Organisasi berhasil diperbarui.');
    }

    private function form(?Entity $organization): View
    {
        $organization?->load('departments.divisions');
        $legacyDepartments = Gate::allows('departments.update')
            ? Department::query()->whereNull('entity_id')->with('divisions')->orderBy('name')->get()
            : collect();

        return view('organizations.form', compact('organization', 'legacyDepartments'));
    }

    /** @param array{code?: ?string, name: string, is_active: bool, departments?: array} $data */
    private function save(array $data, ?Entity $organization): void
    {
        $codeField = 'code';
        $fields = ['code', 'name', 'is_active'];

        try {
            DB::transaction(function () use ($data, $organization, $fields, &$codeField): void {
                $codeField = 'code';
                $entity = $organization ? Entity::query()->lockForUpdate()->findOrFail($organization->id) : new Entity;
                $this->saveFields($entity, Arr::only($data, $fields), $codeField);

                foreach ($data['departments'] ?? [] as $index => $values) {
                    $path = "departments.{$index}";
                    $department = $entity->departments()->make();
                    if (isset($values['id'])) {
                        $department = Department::query()->lockForUpdate()->find($values['id']);
                        if (! $department || ($department->entity_id !== null && $department->entity_id !== $entity->id)) {
                            throw ValidationException::withMessages([$path.'.id' => 'Departemen sudah berubah. Muat ulang formulir.']);
                        }
                        $department->entity()->associate($entity);
                    }
                    $codeField = $path.'.code';
                    $this->saveFields($department, Arr::only($values, [...$fields, 'description']), $codeField);

                    foreach ($values['divisions'] ?? [] as $divisionIndex => $divisionValues) {
                        $codeField = $path.".divisions.{$divisionIndex}.code";
                        $division = isset($divisionValues['id'])
                            ? $department->divisions()->findOrFail($divisionValues['id'])
                            : $department->divisions()->make();
                        $this->saveFields($division, Arr::only($divisionValues, $fields), $codeField);
                    }
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$codeField => 'Kode sudah digunakan dalam organisasi ini.']);
        } catch (QueryException $exception) {
            report($exception);

            throw ValidationException::withMessages(['organization' => 'Organisasi belum tersimpan. Silakan coba lagi.']);
        }
    }

    /** @param array{code?: ?string, name: string, is_active: bool, description?: ?string} $fields */
    private function saveFields(Entity|Department|Division $record, array $fields, string $codeField): void
    {
        if (($fields['code'] ?? null) === null && $record->code !== null) {
            throw ValidationException::withMessages([$codeField => 'Kode sudah diisi. Muat ulang formulir.']);
        }

        $record->fill($fields)->save();
    }
}
