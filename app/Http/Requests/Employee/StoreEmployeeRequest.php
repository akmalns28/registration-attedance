<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $entityId = $this->integer('entity_id') ?: null;
        $departmentId = $this->integer('department_id');
        $currentDepartment = $employee instanceof Employee ? $employee->department : null;
        $keepingDepartment = $currentDepartment?->id === $departmentId;
        $divisionParentIsActive = ! $keepingDepartment
            || ($currentDepartment->is_active && $currentDepartment->entity?->is_active !== false);
        $entity = Rule::exists('entities', 'id')->where(fn ($query) => $query
            ->where('is_active', true)->orWhere('id', $keepingDepartment ? $currentDepartment?->entity_id : null));
        $department = Rule::exists('departments', 'id')->where('entity_id', $entityId)->where(fn ($query) => $query
            ->where('is_active', true)->orWhere('id', $currentDepartment?->id));
        $division = Rule::exists('divisions', 'id')->where('department_id', $departmentId)->where(fn ($query) => $query
            ->where('id', $employee?->division_id)->when($divisionParentIsActive, fn ($query) => $query->orWhere('is_active', true)));
        $nip = Rule::unique('employees', 'nip')->ignore($employee);

        return [
            'nip' => ['required', 'string', 'max:255', $nip],

            'name' => ['required', 'string', 'max:150'],

            'entity_id' => ['nullable', 'integer', 'min:1', $entity],

            'department_id' => ['required', 'integer', 'min:1', $department],

            'division_id' => ['nullable', 'integer', 'min:1', $division],

            'email' => ['nullable', 'email', 'max:255'],

            'phone' => ['nullable', 'string', 'max:20'],

            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function employeeAttributes(): array
    {
        return Arr::except($this->validated(), ['entity_id']);
    }

    protected function prepareForValidation(): void
    {
        $employee = $this->route('employee');
        if ($employee instanceof Employee && $this->integer('department_id') === $employee->department_id) {
            $this->mergeIfMissing(['entity_id' => $employee->department?->entity_id, 'division_id' => $employee->division_id]);
        }

        $this->merge([
            'entity_id' => $this->input('entity_id') === 'legacy' ? null : $this->input('entity_id'),

            'division_id' => $this->input('division_id'),

            'nip' => strtoupper(trim((string) $this->input('nip'))),

            'name' => trim((string) $this->input('name')),

            'email' => filled($this->input('email')) ? strtolower(trim((string) $this->input('email'))) : null,

            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,

            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'nip.required' => 'NIP karyawan wajib diisi.',
            'nip.max' => 'NIP karyawan maksimal 255 karakter.',
            'nip.unique' => 'NIP karyawan sudah digunakan.',
            'name.required' => 'Nama karyawan wajib diisi.',
            'entity_id.exists' => 'Entitas tidak tersedia atau sudah tidak aktif.',
            'department_id.required' => 'Departemen wajib dipilih.',
            'department_id.exists' => 'Departemen tidak tersedia di entitas ini atau sudah tidak aktif.',
            'division_id.exists' => 'Divisi tidak tersedia di departemen ini atau sudah tidak aktif.',
            'email.email' => 'Format email tidak valid.',
        ];
    }
}
