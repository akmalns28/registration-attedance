<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Division;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class SaveOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('organization') === null ? 'departments.create' : 'departments.update';

        return $this->user()?->can($permission) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $code = ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/'];
        $name = ['required', 'string', 'max:150'];
        $active = ['required', 'boolean'];
        $entity = $this->route('organization');
        $entityCodeIsOptional = $entity !== null && $entity->code === null;

        return [
            'code' => $entityCodeIsOptional ? $code : ['required', ...$code],
            'name' => $name,
            'is_active' => $active,
            'departments' => ['sometimes', 'array'],
            'departments.*' => ['required', 'array:id,code,name,is_active,description,divisions'],
            'departments.*.id' => ['nullable', 'integer', 'min:1'],
            'departments.*.code' => ['required_without:departments.*.id', ...$code],
            'departments.*.name' => $name,
            'departments.*.is_active' => $active,
            'departments.*.description' => ['nullable', 'string', 'max:2000'],
            'departments.*.divisions' => ['sometimes', 'array'],
            'departments.*.divisions.*' => ['required', 'array:id,code,name,is_active'],
            'departments.*.divisions.*.id' => ['nullable', 'integer', 'min:1'],
            'departments.*.divisions.*.code' => ['required_without:departments.*.divisions.*.id', ...$code],
            'departments.*.divisions.*.name' => $name,
            'departments.*.divisions.*.is_active' => $active,
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeFields($this->only('code', 'name', 'departments')));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Isi :attribute.',
            'required_without' => 'Isi :attribute.',
            'code.regex' => 'Gunakan huruf, angka, tanda hubung, atau garis bawah pada kode.',
            'departments.*.code.regex' => 'Gunakan huruf, angka, tanda hubung, atau garis bawah pada kode departemen.',
            'departments.*.divisions.*.code.regex' => 'Gunakan huruf, angka, tanda hubung, atau garis bawah pada kode divisi.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'kode entitas',
            'name' => 'nama entitas',
            'is_active' => 'status entitas',
            'departments.*.code' => 'kode departemen',
            'departments.*.name' => 'nama departemen',
            'departments.*.is_active' => 'status departemen',
            'departments.*.description' => 'deskripsi departemen',
            'departments.*.divisions.*.code' => 'kode divisi',
            'departments.*.divisions.*.name' => 'nama divisi',
            'departments.*.divisions.*.is_active' => 'status divisi',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $entity = $this->route('organization');
            $canCreate = $this->user()->can('departments.create');
            $canUpdate = $this->user()->can('departments.update');
            $data = $validator->getData();
            $entityCodes = [];
            $this->validateCode($validator, 'code', $data['code'] ?? null, $entity, Entity::query(), $entityCodes);

            $departmentCodes = [];
            $departmentIds = [];
            foreach ($data['departments'] ?? [] as $index => $values) {
                $field = "departments.{$index}";
                $department = isset($values['id']) ? Department::find($values['id']) : null;
                if (! isset($values['id']) && ! $canCreate) {
                    $validator->errors()->add($field.'.name', 'Anda tidak memiliki izin untuk menambah departemen.');

                    continue;
                }
                if (isset($values['id']) && (! $department || isset($departmentIds[$department->id])
                    || ($department->entity_id !== null && $department->entity_id !== $entity?->id)
                    || ! $canUpdate)) {
                    $validator->errors()->add($field.'.id', 'Departemen tidak dapat ditambahkan atau sudah ada di formulir.');

                    continue;
                }
                if ($department) {
                    $departmentIds[$department->id] = true;
                }
                $this->validateCode($validator, $field.'.code', $values['code'] ?? null, $department,
                    $entity ? Department::query()->where('entity_id', $entity->id) : null, $departmentCodes);
                $this->validateDivisions($validator, $field, $department, $values['divisions'] ?? [], $canCreate);
            }
        }];
    }

    /** @param array<array-key, mixed> $fields */
    private function normalizeFields(array $fields): array
    {
        if (is_string($fields['code'] ?? null)) {
            $fields['code'] = Entity::normalizeCode($fields['code']);
        }
        if (is_string($fields['name'] ?? null)) {
            $fields['name'] = Str::trim($fields['name']);
        }
        foreach (['departments', 'divisions'] as $children) {
            if (is_array($fields[$children] ?? null)) {
                $fields[$children] = array_map(fn (mixed $child): mixed => is_array($child) ? $this->normalizeFields($child) : $child, $fields[$children]);
            }
        }

        return $fields;
    }

    /** @param array<array-key, array{id?: int, code?: string|null}> $divisions */
    private function validateDivisions(Validator $validator, string $field, ?Department $department, array $divisions, bool $canCreate): void
    {
        $codes = [];
        $ids = [];
        foreach ($divisions as $index => $values) {
            $path = $field.".divisions.{$index}";
            $division = isset($values['id']) ? $department?->divisions()->find($values['id']) : null;
            if (! isset($values['id']) && ! $canCreate) {
                $validator->errors()->add($path.'.name', 'Anda tidak memiliki izin untuk menambah divisi.');

                continue;
            }
            if (isset($values['id']) && (! $division || isset($ids[$division->id]))) {
                $validator->errors()->add($path.'.id', 'Divisi tidak berada di departemen ini atau sudah ada di formulir.');

                continue;
            }
            if ($division) {
                $ids[$division->id] = true;
            }
            $this->validateCode($validator, $path.'.code', $values['code'] ?? null, $division,
                $department ? Division::query()->where('department_id', $department->id) : null, $codes);
        }
    }

    /** @param array<string, string> $codes */
    private function validateCode(
        Validator $validator,
        string $field,
        ?string $code,
        Entity|Department|Division|null $record,
        ?Builder $scope,
        array &$codes,
    ): void {
        if ($code === null) {
            if (! $record || $record->code !== null) {
                $validator->errors()->add($field, 'Kode wajib diisi.');
            }

            return;
        }

        $duplicate = $scope?->whereRaw('upper(trim(code)) = ?', [$code])
            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))->exists();
        if ($duplicate || isset($codes[$code])) {
            $validator->errors()->add($field, 'Kode sudah digunakan dalam organisasi ini.');
            if (isset($codes[$code])) {
                $validator->errors()->add($codes[$code], 'Kode sudah digunakan dalam formulir ini.');
            }
        }
        $codes[$code] = $field;
    }
}
