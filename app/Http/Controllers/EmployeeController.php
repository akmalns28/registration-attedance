<?php

namespace App\Http\Controllers;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    /**
     * Display employees.
     */
    public function index(): View
    {
        $employees = Employee::query()->with('department')->orderBy('name')->get();

        return view('employees.index', [
            'employees' => $employees,
        ]);
    }

    /**
     * Show employee creation form.
     */
    public function create(): View
    {
        $departments = Department::query()->active()->orderBy('name')->get();

        return view('employees.create', [
            'departments' => $departments,
        ]);
    }

    /**
     * Store employee.
     */
    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        Employee::create($request->validated());

        return redirect()->route('employees.index')->with('success', 'Karyawan berhasil ditambahkan.');
    }

    /**
     * Show employee editing form.
     */
    public function edit(Employee $employee): View
    {
        $departments = Department::query()
            ->where(function ($query) use ($employee) {
                $query->where('is_active', true)->orWhere('id', $employee->department_id);
            })
            ->orderBy('name')
            ->get();

        return view('employees.edit', [
            'employee' => $employee,
            'departments' => $departments,
        ]);
    }

    /**
     * Update employee.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->validated());

        return redirect()->route('employees.index')->with('success', 'Data karyawan berhasil diperbarui.');
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
