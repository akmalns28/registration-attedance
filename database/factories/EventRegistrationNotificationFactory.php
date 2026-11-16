<?php

namespace Database\Factories;

use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventRegistrationNotification>
 */
class EventRegistrationNotificationFactory extends Factory
{
    protected $model = EventRegistrationNotification::class;

    public function definition(): array
    {
        return [
            'event_registration_id' => EventRegistration::factory(),

            'event_id' => fn (array $attributes) => EventRegistration::query()->findOrFail($attributes['event_registration_id'])->event_id,

            'notification_type' => EventRegistrationNotification::TYPE_INVITATION,

            'dedupe_key' => (string) Str::uuid(),

            'recipient_email' => fake()->safeEmail(),

            'status' => EventRegistrationNotification::STATUS_PENDING,

            'attempts' => 0,

            'scheduled_for' => null,

            'queued_at' => null,

            'last_attempt_at' => null,

            'sent_at' => null,

            'failed_at' => null,

            'last_error' => null,

            'metadata' => null,
        ];
    }

    public function invitation(): static
    {
        return $this->state(
            fn () => [
                'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
            ],
        );
    }

    public function cancellation(): static
    {
        return $this->state(
            fn () => [
                'notification_type' => EventRegistrationNotification::TYPE_CANCELLATION,
            ],
        );
    }

    public function reactivation(): static
    {
        return $this->state(
            fn () => [
                'notification_type' => EventRegistrationNotification::TYPE_REACTIVATION,
            ],
        );
    }

    public function reminder(): static
    {
        return $this->state(
            fn () => [
                'notification_type' => EventRegistrationNotification::TYPE_REMINDER,
            ],
        );
    }

    public function queued(): static
    {
        return $this->state(
            fn () => [
                'status' => EventRegistrationNotification::STATUS_QUEUED,

                'queued_at' => now(),
            ],
        );
    }

    public function sent(): static
    {
        return $this->state(
            fn () => [
                'status' => EventRegistrationNotification::STATUS_SENT,

                'attempts' => 1,

                'sent_at' => now(),
            ],
        );
    }

    public function failed(): static
    {
        return $this->state(
            fn () => [
                'status' => EventRegistrationNotification::STATUS_FAILED,

                'attempts' => 1,

                'failed_at' => now(),

                'last_error' => 'Simulated email delivery failure.',
            ],
        );
    }

    public function skipped(): static
    {
        return $this->state(
            fn () => [
                'status' => EventRegistrationNotification::STATUS_SKIPPED,

                'recipient_email' => null,
            ],
        );
    }
}
