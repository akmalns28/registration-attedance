<?php

namespace App\Http\Controllers;

use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments.
     */
    public function index(Request $request, OrganizationController $organizations): View
    {
        return $organizations->index($request);
    }

    /**
     * Show the form for creating a new department.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('organizations.create');
    }

    /**
     * Store a newly created department.
     */
    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Department::create($data);

        return redirect()->route('departments.index')->with('success', 'Departemen berhasil ditambahkan.');
    }

    /**
     * Show the form for editing the specified department.
     */
    public function edit(Department $department): View|RedirectResponse
    {
        if ($department->entity_id !== null) {
            return redirect()->route('organizations.edit', $department->entity_id);
        }

        return view('organizations.form', ['organization' => null, 'legacyDepartment' => $department]);
    }

    /**
     * Update the specified department.
     */
    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $data = $request->validated();

        $department->update($data);

        return redirect()->route('departments.index')->with('success', 'Departemen berhasil diperbarui.');
    }

    /**
     * Remove the specified department.
     */
    public function destroy(Department $department): RedirectResponse
    {
        Gate::authorize('departments.delete');

        if ($department->divisions()->exists()) {
            return redirect()->route('departments.index')->with('error', 'Departemen masih memiliki divisi. Nonaktifkan departemen jika sudah tidak digunakan.');
        }

        if ($department->employees()->exists()) {
            return redirect()->route('departments.index')->with('error', 'Departemen tidak dapat dihapus karena sudah digunakan oleh karyawan. Nonaktifkan departemen jika sudah tidak digunakan.');
        }

        $department->delete();

        return redirect()->route('departments.index')->with('success', 'Departemen berhasil dihapus.');
    }
}
