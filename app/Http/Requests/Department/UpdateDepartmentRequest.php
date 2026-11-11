<?php

namespace App\Http\Requests\Department;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('departments.update') ?? false;
    }

    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('departments', 'code')->ignore($department)],

            'name' => ['required', 'string', 'max:150', Rule::unique('departments', 'name')->ignore($department)],

            'description' => ['nullable', 'string', 'max:2000'],

            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
