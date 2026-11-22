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
        $code = Rule::unique('departments', 'code')->where('entity_id', $department->entity_id)->ignore($department);

        return [
            'code' => [$department->code === null ? 'nullable' : 'required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', $code],

            'name' => ['required', 'string', 'max:150'],

            'description' => ['nullable', 'string', 'max:2000'],

            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? Department::normalizeCode($this->input('code')) : $this->input('code'),
            'name' => trim((string) $this->input('name')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
