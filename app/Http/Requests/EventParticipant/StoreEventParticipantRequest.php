<?php

namespace App\Http\Requests\EventParticipant;

use App\Models\EventRegistration;
use App\Services\EventRegistrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEventParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('events.update') ?? false;
    }

    public function rules(): array
    {
        $limit = EventRegistrationService::MAX_BULK_PARTICIPANTS;
        $dayRules = [
            'day_selection' => ['sometimes', 'required', Rule::in(['all', 'selected'])],
            'day_ids' => ['required_if:day_selection,selected', 'array', 'max:366'],
            'day_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];

        if ($this->has('participant_groups')) {
            return [
                ...$dayRules,
                'participant_groups' => ['required', 'array:employee,external'],
                'participant_groups.*' => ['required', 'array', 'list', "max:{$limit}"],
                'participant_groups.employee.*' => ['required', 'integer', 'min:1', 'distinct'],
                'participant_groups.external.*' => ['required', 'integer', 'min:1', 'distinct'],
            ];
        }

        return [
            ...$dayRules,
            'participant_type' => [
                'required',
                'string',
                Rule::in(EventRegistration::types()),
            ],

            'participant_ids' => [
                'required',
                'array',
                'min:1',
                "max:{$limit}",
            ],

            'participant_ids.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $groups = $this->input('participant_groups');
            if (is_array($groups) && count(Arr::collapse($groups)) > EventRegistrationService::MAX_BULK_PARTICIPANTS) {
                $validator->errors()->add('participant_groups', $this->messages()['participant_ids.max']);
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('participant_type'))) {
            $this->merge([
                'participant_type' => strtolower(
                    trim($this->input('participant_type'))
                ),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return route('events.show', $this->route('event')).'#event-participants';
    }

    public function messages(): array
    {
        return [
            'participant_type.required' => 'Jenis peserta wajib dipilih.',

            'participant_type.in' => 'Jenis peserta tidak valid.',

            'participant_ids.required' => 'Pilih minimal satu peserta.',

            'participant_ids.array' => 'Format daftar peserta tidak valid.',

            'participant_ids.min' => 'Pilih minimal satu peserta.',

            'participant_ids.max' => 'Maksimal 500 peserta dalam satu proses.',

            'participant_ids.*.integer' => 'ID peserta harus berupa angka.',

            'participant_ids.*.min' => 'ID peserta tidak valid.',

            'participant_ids.*.distinct' => 'Terdapat peserta duplikat dalam pilihan.',
            'day_selection.in' => 'Pilih semua hari atau hari tertentu.',
            'day_ids.required_if' => 'Pilih minimal satu hari undangan.',
        ];
    }
}
