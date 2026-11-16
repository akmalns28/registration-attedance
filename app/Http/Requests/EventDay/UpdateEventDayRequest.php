<?php

namespace App\Http\Requests\EventDay;

use Illuminate\Validation\Rule;

class UpdateEventDayRequest extends StoreEventDayRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $event = $this->route('event');
        $day = $this->route('day');

        $rules['date'] = ['required', 'date_format:Y-m-d', 'after_or_equal:'.$event->start_date->format('Y-m-d'), 'before_or_equal:'.$event->end_date->format('Y-m-d'), Rule::unique('event_days', 'date')->where('event_id', $event->id)->ignore($day->id)];

        return $rules;
    }
}
