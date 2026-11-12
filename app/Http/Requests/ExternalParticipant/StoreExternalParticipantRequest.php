<?php

namespace App\Http\Requests\ExternalParticipant;

use Illuminate\Foundation\Http\FormRequest;

class StoreExternalParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('external-participants.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],

            'email' => ['nullable', 'email', 'max:255'],

            'phone' => ['nullable', 'string', 'max:20'],

            'organization' => ['nullable', 'string', 'max:150'],

            'position' => ['nullable', 'string', 'max:150'],

            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),

            'email' => filled($this->input('email')) ? strtolower(trim((string) $this->input('email'))) : null,

            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,

            'organization' => filled($this->input('organization')) ? trim((string) $this->input('organization')) : null,

            'position' => filled($this->input('position')) ? trim((string) $this->input('position')) : null,

            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama peserta wajib diisi.',
            'name.max' => 'Nama peserta maksimal 150 karakter.',
            'email.email' => 'Format email tidak valid.',
            'phone.max' => 'Nomor HP maksimal 20 karakter.',
            'organization.max' => 'Nama organisasi maksimal 150 karakter.',
            'position.max' => 'Jabatan maksimal 150 karakter.',
            'is_active.required' => 'Status peserta wajib diisi.',
            'is_active.boolean' => 'Status peserta tidak valid.',
        ];
    }
}
