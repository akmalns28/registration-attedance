<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Zxing\QrReader;

class RegistrationQrImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('events.view', 'web');
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('events.view');

        return $user;
    }

    public function test_participant_qr_image_decodes_to_exact_raw_token_and_downloads_as_png(): void
    {
        $registration = EventRegistration::factory()->create(['uuid' => '01a1cf4f-9c62-721a-9b5a-d478aea18229']);
        $parameters = [$registration->event, $registration];

        $response = $this->actingAs($this->viewer())->get(route('events.participants.qr', $parameters));

        $response->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename="registration-'.$registration->uuid.'.png"');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('RA1:'.$registration->uuid, (new QrReader($response->getContent(), QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]));

        $this->get(route('events.participants.qr', [...$parameters, 'download' => 1]))
            ->assertOk()->assertDownload('registration-'.$registration->uuid.'.png');
        $registration->update(['status' => EventRegistration::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $this->assertSame($response->getContent(), $this->get(route('events.participants.qr', $parameters))->getContent());
    }

    public function test_participant_table_shows_qr_image_download_and_exact_payload(): void
    {
        $registration = EventRegistration::factory()->create();
        $url = route('events.participants.qr', [$registration->event, $registration]);

        $this->actingAs($this->viewer())->get(route('events.show', $registration->event))
            ->assertOk()->assertSee('QR Code')->assertSee('src="'.$url.'"', false)
            ->assertSee('RA1:'.$registration->uuid)->assertSee('aria-label="Unduh QR PNG"', false)
            ->assertSee('href="'.route('events.participants.qr', ['event' => $registration->event, 'registration' => $registration, 'download' => 1]).'"', false);
    }

    public function test_qr_images_require_authentication_and_event_view_permission(): void
    {
        $registration = EventRegistration::factory()->create();
        $url = route('events.participants.qr', [$registration->event, $registration]);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_active' => true]))->get($url)->assertForbidden();
    }

    public function test_registration_qr_cannot_be_requested_through_another_event(): void
    {
        $registration = EventRegistration::factory()->create();

        $this->actingAs($this->viewer())->get(route('events.participants.qr', [Event::factory()->create(), $registration]))
            ->assertNotFound();
    }
}
