<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return ($this->user()?->can('users.manage') ?? false) && $target instanceof \App\Models\User && $target->id !== $this->user()->id && !$target->hasRole('super-admin');
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                function ($attribute, $value, $fail) use ($user) {
                    if ($user->keycloak_id && $value !== $user->email) {
                        $fail('Email pengguna yang sudah terhubung ke Keycloak tidak dapat diubah.');
                    }
                },
            ],

            'roles' => ['required', 'array', 'min:1'],

            'roles.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'web')->where('name', '!=', 'super-admin')],

            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
