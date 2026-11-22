<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Division;
use App\Models\Entity;
use Illuminate\Support\Str;

class EmployeeOrganizationReferences
{
    /** @var array<string, array<int, Entity>> */
    private array $entities = [];

    /** @var array<int, array<string, array<int, Department>>> */
    private array $departments = [];

    /** @var array<int, array<string, array<int, Division>>> */
    private array $divisions = [];

    /** @var array<int, Department> */
    private array $legacyDepartments = [];

    public function __construct()
    {
        foreach (Entity::query()->get(['id', 'code', 'name', 'is_active']) as $entity) {
            $this->addAliases($this->entities, $entity);
        }
        foreach (Department::query()->with('divisions:id,department_id,code,name,is_active')->get(['id', 'entity_id', 'code', 'name', 'is_active']) as $department) {
            $entityId = $department->entity_id ?? 0;
            $this->departments[$entityId] ??= [];
            $this->addAliases($this->departments[$entityId], $department);
            if ($department->entity_id === null) {
                $this->legacyDepartments[$department->id] = $department;
            }
            $this->divisions[$department->id] = [];
            foreach ($department->divisions as $division) {
                $this->addAliases($this->divisions[$department->id], $division);
            }
        }
    }

    /**
     * @param  array<string, string|null>  $input
     * @return array{attributes: array<string, int|null>, display: array<string, string>, errors: list<string>}
     */
    public function resolve(array $input): array
    {
        $result = ['attributes' => ['entity_id' => null, 'department_id' => null, 'division_id' => null], 'display' => [], 'errors' => []];
        if (filled($input['entity'] ?? null)) {
            $entity = $this->resolveReference($input['entity'], 'Entitas', $this->entities);
            if (is_string($entity)) {
                $result['errors'][] = $entity;

                return $result;
            }
            $result['attributes']['entity_id'] = $entity->id;
            $result['display']['entity'] = $entity->organizationLabel();
        }

        $entityId = $result['attributes']['entity_id'];
        $departmentInput = $input['department'] ?? null;
        $departmentKey = $this->key($departmentInput ?? '');
        $legacyById = $entityId === null && ctype_digit($departmentKey)
            ? ($this->legacyDepartments[ltrim($departmentKey, '0')] ?? null)
            : null;
        $department = $this->resolveReference($departmentInput, 'Departemen', $this->departments[$entityId ?? 0] ?? [], $legacyById);
        if (is_string($department)) {
            $result['errors'][] = $department;

            return $result;
        }
        $result['attributes']['department_id'] = $department->id;
        $result['display']['department'] = $department->organizationLabel();

        if (filled($input['division'] ?? null)) {
            $division = $this->resolveReference($input['division'], 'Divisi', $this->divisions[$department->id]);
            if (is_string($division)) {
                $result['errors'][] = $division;
            } else {
                $result['attributes']['division_id'] = $division->id;
                $result['display']['division'] = $division->organizationLabel();
            }
        }

        return $result;
    }

    /** @param array<string, array<int, Entity|Department|Division>> $aliases */
    private function addAliases(array &$aliases, Entity|Department|Division $reference): void
    {
        foreach ([$reference->code, $reference->name, $reference->organizationLabel()] as $value) {
            if (filled($value)) {
                $aliases[$this->key($value)][$reference->id] = $reference;
            }
        }
    }

    /** @param array<string, array<int, Entity|Department|Division>> $aliases */
    private function resolveReference(?string $input, string $label, array $aliases, ?Department $legacyById = null): Entity|Department|Division|string
    {
        $key = $this->key($input ?? '');
        if ($key === '') {
            return "{$label} wajib diisi.";
        }
        $matches = $aliases[$key] ?? [];
        if ($legacyById !== null) {
            $matches[$legacyById->id] = $legacyById;
        }
        if (count($matches) > 1) {
            return "{$label} memiliki lebih dari satu kecocokan. Gunakan referensi yang unik.";
        }

        $reference = reset($matches);

        return match (true) {
            $reference === false => "{$label} tidak tersedia. Gunakan kode atau nama dari daftar pada halaman impor.",
            ! $reference->is_active => "{$label} sudah tidak aktif.",
            default => $reference,
        };
    }

    private function key(string $value): string
    {
        return Str::lower(Str::squish($value));
    }
}
