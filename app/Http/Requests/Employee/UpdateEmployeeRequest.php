<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'nik' => ['required', 'string', 'max:30', Rule::unique('employees', 'nik')->ignore($employee)],

            'name' => ['required', 'string', 'max:150'],

            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where(function ($query) use ($employee) {
                    $query->where(function ($query) use ($employee) {
                        $query->where('is_active', true)->orWhere('id', $employee->department_id);
                    });
                }),
            ],

            'email' => ['nullable', 'email', 'max:255'],

            'phone' => ['nullable', 'string', 'max:20'],

            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nik' => strtoupper(trim((string) $this->input('nik'))),

            'name' => trim((string) $this->input('name')),

            'email' => filled($this->input('email')) ? strtolower(trim((string) $this->input('email'))) : null,

            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,

            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'nik.required' => 'NIK karyawan wajib diisi.',
            'nik.unique' => 'NIK karyawan sudah digunakan.',
            'name.required' => 'Nama karyawan wajib diisi.',
            'department_id.required' => 'Departemen wajib dipilih.',
            'department_id.exists' => 'Departemen tidak tersedia atau sudah tidak aktif.',
            'email.email' => 'Format email tidak valid.',
        ];
    }
}
