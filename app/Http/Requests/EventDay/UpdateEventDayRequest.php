<?php

namespace App\Http\Requests\EventDay;

use Illuminate\Validation\Rule;

class UpdateEventDayRequest extends StoreEventDayRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $day = $this->route('day');
        $this->mergeIfMissing([
            'start_time' => $day->start_time === null ? null : substr($day->start_time, 0, 5),
            'end_time' => $day->end_time === null ? null : substr($day->end_time, 0, 5),
        ]);
    }

    public function rules(): array
    {
        $rules = parent::rules();

        $event = $this->route('event');
        $day = $this->route('day');

        $rules['date'] = ['required', 'date_format:Y-m-d', 'after_or_equal:'.$event->start_date->format('Y-m-d'), 'before_or_equal:'.$event->end_date->format('Y-m-d'), Rule::unique('event_days', 'date')->where('event_id', $event->id)->ignore($day->id)];

        return $rules;
    }
}
