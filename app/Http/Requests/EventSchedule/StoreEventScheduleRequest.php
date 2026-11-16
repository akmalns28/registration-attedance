<?php

namespace App\Http\Requests\EventSchedule;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('events.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],

            'description' => ['nullable', 'string', 'max:5000'],

            'start_time' => ['required', 'date_format:H:i'],

            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],

            'pic' => ['nullable', 'string', 'max:255'],

            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],

        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),

            'description' => $this->normalizeOptional('description'),

            'pic' => $this->normalizeOptional('pic'),

            'sort_order' => $this->input('sort_order', 0),

        ]);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->filled('schedule_form')) {
            return route('events.show', $this->route('event')).'#event-days';
        }

        return parent::getRedirectUrl();
    }

    protected function normalizeOptional(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Nama kegiatan wajib diisi.',

            'title.max' => 'Nama kegiatan maksimal 200 karakter.',

            'start_time.required' => 'Jam mulai wajib diisi.',

            'start_time.date_format' => 'Format jam mulai tidak valid.',

            'end_time.required' => 'Jam selesai wajib diisi.',

            'end_time.date_format' => 'Format jam selesai tidak valid.',

            'end_time.after' => 'Jam selesai harus lebih besar dari jam mulai.',

            'sort_order.integer' => 'Urutan kegiatan harus berupa angka.',

            'sort_order.min' => 'Urutan kegiatan minimal 0.',
        ];
    }
}
