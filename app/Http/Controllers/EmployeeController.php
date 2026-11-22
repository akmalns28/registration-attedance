<?php

namespace App\Http\Controllers;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Entity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    /**
     * Display employees.
     */
    public function index(): View
    {
        $employees = Employee::query()->with(['department.entity', 'division'])->orderBy('name')->get();

        return view('employees.index', [
            'employees' => $employees,
        ]);
    }

    /**
     * Show employee creation form.
     */
    public function create(): View
    {
        return view('employees.create', $this->organizationOptions());
    }

    /**
     * Store employee.
     */
    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        Employee::create($request->employeeAttributes());

        return redirect()->route('employees.index')->with('success', 'Karyawan berhasil ditambahkan.');
    }

    /**
     * Show employee editing form.
     */
    public function edit(Employee $employee): View
    {
        return view('employees.edit', ['employee' => $employee, ...$this->organizationOptions($employee)]);
    }

    /**
     * Update employee.
     */
    public function update(StoreEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->employeeAttributes());

        return redirect()->route('employees.index')->with('success', 'Data karyawan berhasil diperbarui.');
    }

    /** @return array{entities: Collection, departments: Collection} */
    private function organizationOptions(?Employee $employee = null): array
    {
        $entities = Entity::query()->where('is_active', true)->orWhere('id', $employee?->department?->entity_id)
            ->orderBy('name')->get();
        $activeEntityIds = $entities->where('is_active', true)->modelKeys();
        $departments = Department::query()
            ->where(fn ($query) => $query->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('entity_id')->orWhereIn('entity_id', $activeEntityIds)))
            ->orWhere('id', $employee?->department_id)
            ->with(['divisions' => fn ($query) => $query
                ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $employee?->division_id))
                ->orderBy('name')])
            ->orderBy('name')->get();

        foreach ($departments as $department) {
            if (! $department->is_active || ($department->entity_id !== null && ! in_array($department->entity_id, $activeEntityIds, true))) {
                $department->setRelation('divisions', $department->divisions->where('id', $employee?->division_id));
            }
        }

        return compact('entities', 'departments');
    }

    /**
     * Delete employee.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        Gate::authorize('employees.manage');

        $employee->delete();

        return redirect()->route('employees.index')->with('success', 'Karyawan berhasil dihapus.');
    }
}
