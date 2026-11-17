<?php

namespace App\Http\Requests\Analytic;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyticFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reports.view') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'uuid', Rule::exists('events', 'uuid')],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('start_date'), ['after_or_equal:start_date'])],
            'event_day' => [
                'nullable', Rule::prohibitedIf(! $this->filled('event')), 'integer', 'min:1',
                Rule::exists('event_days', 'id')->where(fn (Builder $query): Builder => $query
                    ->whereIn('event_id', fn (Builder $events): Builder => $events->select('id')->from('events')
                        ->where('uuid', is_string($this->input('event')) ? $this->input('event') : null))),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'event.uuid' => 'Format Event tidak valid.',
            'event.exists' => 'Event tidak ditemukan.',
            'event_day.prohibited' => 'Pilih Event sebelum memilih Event Day.',
            'event_day.exists' => 'Event Day tidak ditemukan pada Event yang dipilih.',
            'event_day.integer' => 'Event Day tidak valid.',
            'start_date.date_format' => 'Format tanggal mulai harus YYYY-MM-DD.',
            'end_date.date_format' => 'Format tanggal selesai harus YYYY-MM-DD.',
            'end_date.after_or_equal' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
        ];
    }
}
