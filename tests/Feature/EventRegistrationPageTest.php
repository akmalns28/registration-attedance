<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventRegistrationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_displays_details_without_login(): void
    {
        $registration = EventRegistration::factory()->create();

        $response = $this->get(route('registrations.show', $registration));

        $response->assertOk()
            ->assertSeeText($registration->registration_number)
            ->assertSeeText($registration->event->title)
            ->assertSeeText('Terdaftar')
            ->assertDontSeeText($registration->employee->name)
            ->assertDontSeeText($registration->employee->nip)
            ->assertDontSeeText($registration->employee->email);
    }

    public function test_external_registration_is_visible_without_exposing_participant_details(): void
    {
        $registration = EventRegistration::factory()->external()->create();

        $response = $this->get('/registrations/'.$registration->uuid);

        $response->assertOk()
            ->assertSeeText($registration->registration_number)
            ->assertSeeText('Terdaftar')
            ->assertDontSeeText($registration->externalParticipant->name)
            ->assertDontSeeText($registration->externalParticipant->email);
    }

    public function test_cancelled_registration_displays_cancelled_status(): void
    {
        $registration = EventRegistration::factory()->cancelled()->create();

        $response = $this->get('/registrations/'.$registration->uuid);

        $response->assertOk()
            ->assertSeeText('Dibatalkan')
            ->assertDontSeeText('Terdaftar');
    }

    public function test_unknown_registration_returns_404(): void
    {
        $this->get('/registrations/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_numeric_registration_id_cannot_be_used_instead_of_uuid(): void
    {
        $registration = EventRegistration::factory()->create();

        $this->get('/registrations/'.$registration->id)->assertNotFound();
    }

    public function test_event_title_is_escaped_on_registration_page(): void
    {
        $event = Event::factory()->create(['title' => '<script>alert("event")</script>']);
        $registration = EventRegistration::factory()->for($event)->create();

        $response = $this->get('/registrations/'.$registration->uuid);

        $response->assertOk()
            ->assertSee($event->title)
            ->assertDontSee($event->title, false);
    }
}
