<?php

namespace App\Http\Requests\Attendance;

use App\Models\EventRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('attendance.scan') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $event = $this->route('event');
        $context = $event ? [] : [
            'event_id' => ['required', 'integer', 'min:1', 'exists:events,id'],
            'event_day_id' => ['required', 'integer', 'min:1', 'exists:event_days,id'],
        ];

        if ($this->routeIs('attendance.manual.search', 'events.days.manual.search')) {
            return [...$context, 'participant_type' => ['required', Rule::in(EventRegistration::types())],
                'search' => ['required', 'string', 'min:2', 'max:100']];
        }

        return [...$context, 'registration_id' => ['required', 'integer', 'min:1',
            Rule::exists('event_registrations', 'id')->where('event_id', $event?->id ?? $this->integer('event_id'))]];
    }
}
