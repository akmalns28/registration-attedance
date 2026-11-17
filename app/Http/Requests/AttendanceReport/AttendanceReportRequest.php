<?php

namespace App\Http\Requests\AttendanceReport;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('events.view') ?? false) && $this->user()->can('reports.view');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['prohibited'],
            'event_id' => ['prohibited'],
            'registration_status' => ['prohibited'],
            'include_cancelled' => ['prohibited'],
            'event_day' => ['nullable', 'integer', 'min:1', Rule::exists('event_days', 'id')->where('event_id', $this->route('event')->id)],
            'participant_type' => ['nullable', Rule::in(['employee', 'external'])],
            'attendance_status' => ['nullable', Rule::in(['present', 'not_present'])],
            'department_id' => ['nullable', Rule::prohibitedIf($this->input('participant_type') === 'external'), 'integer', 'min:1', Rule::exists('departments', 'id')],
            'draw' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'start' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'length' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'array:value,regex'],
            'search.value' => ['nullable', 'string', 'max:200'],
            'search.regex' => ['sometimes', Rule::in([false, 0, 'false', '0'])],
            'order' => ['sometimes', 'array', 'max:1'],
            'order.*' => ['array:column,dir'],
            'order.*.column' => ['required', 'integer', 'between:1,9'],
            'order.*.dir' => ['required', Rule::in(['asc', 'desc'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'event_day.exists' => 'Event Day tidak ditemukan pada Event ini.',
            'department_id.exists' => 'Departemen tidak ditemukan.',
            'department_id.prohibited' => 'Filter departemen hanya tersedia untuk peserta Employee.',
            'search.value.max' => 'Pencarian maksimal 200 karakter.',
            'search.regex.in' => 'Pencarian menggunakan teks biasa.',
            'length.max' => 'Maksimal 100 baris per halaman.',
            'event.prohibited' => 'Konteks Event ditentukan oleh halaman laporan.',
            'event_id.prohibited' => 'Konteks Event ditentukan oleh halaman laporan.',
        ];
    }
}
