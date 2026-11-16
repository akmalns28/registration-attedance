<?php

namespace App\Http\Requests\EventDay;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('events.update') ?? false;
    }

    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');

        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$event->start_date->format('Y-m-d'), 'before_or_equal:'.$event->end_date->format('Y-m-d'), Rule::unique('event_days', 'date')->where('event_id', $event->id)],

            'title' => ['nullable', 'string', 'max:150'],

            'description' => ['nullable', 'string', 'max:5000'],

            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->filled('title') ? trim((string) $this->input('title')) : null,

            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,

            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Tanggal pelaksanaan wajib diisi.',

            'date.date_format' => 'Format tanggal tidak valid.',

            'date.after_or_equal' => 'Tanggal tidak boleh sebelum tanggal mulai event.',

            'date.before_or_equal' => 'Tanggal tidak boleh melewati tanggal selesai event.',

            'date.unique' => 'Tanggal tersebut sudah terdaftar pada event ini.',

            'title.max' => 'Nama hari maksimal 150 karakter.',
        ];
    }
}
