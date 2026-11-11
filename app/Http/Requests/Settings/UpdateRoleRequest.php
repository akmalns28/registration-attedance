<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return ($this->user()?->can('roles.manage') ?? false) && $role?->guard_name === 'web' && $role?->name !== 'super-admin';
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->id), Rule::notIn(['super-admin'])],

            'permissions' => ['nullable', 'array'],

            'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }
}
