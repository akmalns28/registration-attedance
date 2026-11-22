<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('departments.create') ?? false;
    }

    public function rules(): array
    {
        $entityId = $this->integer('entity_id');

        return [
            'entity_id' => ['required', 'integer', Rule::exists('entities', 'id')],

            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('departments', 'code')->where('entity_id', $entityId)],

            'name' => ['required', 'string', 'max:150'],

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

    public function messages(): array
    {
        return [
            'code.required' => 'Kode departemen wajib diisi.',
            'code.unique' => 'Kode departemen sudah digunakan.',
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, underscore, dan tanda hubung.',
            'name.required' => 'Nama departemen wajib diisi.',
        ];
    }
}
