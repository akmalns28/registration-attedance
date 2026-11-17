<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateAttendanceQrRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('attendance.scan') ?? false)
            && ((! $this->query->has('event') && ! $this->query->has('day'))
                || ($this->query->has('event') && $this->hasValidRelativeSignature()));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $eventExists = Rule::exists('events', 'id');
        $dayExists = Rule::exists('event_days', 'id');

        if ($this->query->has('event')) {
            $eventExists->where('uuid', $this->query('event'));
        }

        if ($this->query->has('day')) {
            $dayExists->where('id', $this->query('day'));
        }

        return [
            'event_id' => ['required', 'integer', 'min:1', $eventExists],
            'event_day_id' => ['required', 'integer', 'min:1', $dayExists],
            'qr_code' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_id.required' => 'Pilih event terlebih dahulu.',
            'event_id.exists' => 'Event tidak ditemukan.',
            'event_day_id.required' => 'Pilih hari pelaksanaan terlebih dahulu.',
            'event_day_id.exists' => 'Hari pelaksanaan tidak ditemukan.',
            'qr_code.required' => 'QR Code wajib dipindai.',
            'qr_code.string' => 'Format QR Code tidak valid.',
            'qr_code.max' => 'Format QR Code terlalu panjang.',
        ];
    }
}
