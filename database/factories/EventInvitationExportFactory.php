<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventInvitationExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventInvitationExport>
 */
class EventInvitationExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'requested_by' => User::factory(),
            'participant_type' => 'all',
            'participant_count' => 1,
            'batch_size' => 5,
            'last_registration_id' => 1,
        ];
    }
}
