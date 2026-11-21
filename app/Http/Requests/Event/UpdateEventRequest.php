<?php

namespace App\Http\Requests\Event;

use Illuminate\Support\Arr;

class UpdateEventRequest extends StoreEventRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('events.update') ?? false;
    }

    public function rules(): array
    {
        return Arr::except(parent::rules(), ['auto_create_event_days']);
    }
}
