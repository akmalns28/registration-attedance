<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class OrganizationFilters
{
    /** @return array<string, Collection> */
    public static function options(): array
    {
        return [
            'entity' => Entity::query()->orderBy('name')->get(['id', 'code', 'name']),
            'department' => Department::query()->orderBy('name')->get(['id', 'entity_id', 'code', 'name']),
            'division' => Division::query()->orderBy('name')->get(['id', 'department_id', 'code', 'name']),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function rules(Request $request, bool $external = false): array
    {
        $id = ['nullable', Rule::prohibitedIf($external), 'integer', 'min:1'];
        $department = Rule::exists('departments', 'id');
        if ($request->filled('entity_id')) {
            $department->where('entity_id', $request->integer('entity_id'));
        }

        return [
            'entity_id' => [...$id, Rule::exists('entities', 'id')],
            'department_id' => [...$id, $department],
            'division_id' => [...$id, Rule::exists('divisions', 'id')->where('department_id', $request->integer('department_id'))],
        ];
    }

    /** @param array<string, mixed> $filters */
    public static function applyToEmployees(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['entity_id'] ?? null, fn (Builder $query, int|string $id) => $query
                ->whereHas('department', fn (Builder $department) => $department->where('entity_id', $id)))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('employees.department_id', $id))
            ->when($filters['division_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('employees.division_id', $id));
    }

    /** @param array<string, mixed> $filters */
    public static function applyToRegistrations(Builder|QueryBuilder $query, array $filters): void
    {
        if (self::hasSelection($filters)) {
            $employeeIds = self::applyToEmployees(Employee::query(), $filters)->select('employees.id');
            $query->where('event_registrations.registration_type', EventRegistration::TYPE_EMPLOYEE)
                ->whereIn('event_registrations.employee_id', $employeeIds);
        }
    }

    /** @param array<string, mixed> $filters */
    public static function hasSelection(array $filters): bool
    {
        return filled($filters['entity_id'] ?? null) || filled($filters['department_id'] ?? null) || filled($filters['division_id'] ?? null);
    }
}
