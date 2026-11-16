<?php

namespace Tests\Feature;

use App\Mail\EventRegistrationMail;
use App\Models\Employee;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Services\EventRegistrationService;
use App\Services\RegistrationQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use Zxing\QrReader;

class RegistrationQrCodeTest extends TestCase
{
    use RefreshDatabase;

    private RegistrationQrCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RegistrationQrCodeService::class);
    }

    private function registration(): EventRegistration
    {
        return EventRegistration::factory()->create();
    }

    private function notification(EventRegistration $registration, string $type): EventRegistrationNotification
    {
        return EventRegistrationNotification::factory()->create([
            'event_id' => $registration->event_id,
            'event_registration_id' => $registration->id,
            'notification_type' => $type,
        ]);
    }

    public function test_qr_payload_uses_existing_registration_uuid_in_versioned_token(): void
    {
        $registration = EventRegistration::factory()->create([
            'uuid' => '01a1cf4f-9c62-721a-9b5a-d478aea18229',
        ]);

        $this->assertSame('RA1:01a1cf4f-9c62-721a-9b5a-d478aea18229', $this->service->payload($registration));
        $this->assertSame('01a1cf4f-9c62-721a-9b5a-d478aea18229', $registration->fresh()->uuid);
    }

    public function test_qr_payload_does_not_contain_a_url(): void
    {
        $registration = $this->registration();

        $payload = $this->service->payload($registration);

        $this->assertFalse(filter_var($payload, FILTER_VALIDATE_URL));
        $this->assertStringNotContainsString('://', $payload);
    }

    public function test_qr_payload_does_not_depend_on_application_url(): void
    {
        $registration = $this->registration();
        config(['app.url' => 'http://localhost:8000']);
        URL::forceRootUrl('http://localhost:8000');

        $localPayload = $this->service->payload($registration);
        config(['app.url' => 'https://attendance.example.test']);
        URL::forceRootUrl('https://attendance.example.test');
        $productionPayload = $this->service->payload($registration);

        $this->assertSame('RA1:'.$registration->uuid, $localPayload);
        $this->assertSame($localPayload, $productionPayload);
    }

    public function test_qr_payload_does_not_contain_nip(): void
    {
        $employee = Employee::factory()->create([
            'nip' => '9876543210123456',
            'name' => 'Private Employee Name',
            'email' => 'private.employee@example.test',
            'phone' => '081234567890',
            'is_active' => true,
        ]);

        $registration = EventRegistration::factory()
            ->employee()
            ->create([
                'employee_id' => $employee->id,
            ]);

        $payload = $this->service->payload($registration);

        $this->assertStringNotContainsString($employee->nip, $payload);
        $this->assertStringNotContainsString($employee->name, $payload);
        $this->assertStringNotContainsString($employee->email, $payload);
        $this->assertStringNotContainsString($employee->phone, $payload);
        $this->assertSame('RA1:'.$registration->uuid, $payload);
    }

    public function test_qr_code_can_be_generated_as_png(): void
    {
        $registration = $this->registration();

        $png = $this->service->generatePng($registration);

        $this->assertNotEmpty($png);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
    }

    public function test_generated_png_decodes_to_exact_registration_token(): void
    {
        $registration = $this->registration();

        $png = $this->service->generatePng($registration);
        $decodedPayload = (new QrReader($png, QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]);

        $this->assertSame('RA1:'.$registration->uuid, $decodedPayload);
    }

    public function test_same_registration_produces_same_payload(): void
    {
        $registration = $this->registration();

        $first = $this->service->payload($registration);

        $second = $this->service->payload($registration->fresh());

        $this->assertSame($first, $second);
    }

    public function test_cancellation_does_not_change_qr_payload(): void
    {
        $registration = $this->registration();

        $originalPayload = $this->service->payload($registration);

        $registration->update([
            'status' => EventRegistration::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->assertSame($originalPayload, $this->service->payload($registration->fresh()));
    }

    public function test_qr_filename_uses_registration_uuid(): void
    {
        $registration = $this->registration();

        $this->assertSame('registration-'.$registration->uuid.'.png', $this->service->filename($registration));
    }

    public function test_reactivation_does_not_change_qr_payload(): void
    {
        $registration = EventRegistration::factory()->cancelled()->create();
        $originalUuid = $registration->uuid;
        $originalPayload = $this->service->payload($registration);

        $reactivated = app(EventRegistrationService::class)->reactivate($registration);

        $this->assertSame(EventRegistration::STATUS_REGISTERED, $reactivated->status);
        $this->assertSame($originalUuid, $reactivated->uuid);
        $this->assertSame($originalPayload, $this->service->payload($reactivated));
    }

    public function test_invitation_email_has_qr_attachment(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration, EventRegistrationNotification::TYPE_INVITATION);

        $mail = new EventRegistrationMail($notification);

        $this->assertCount(1, $mail->attachments());

        $mail->assertHasAttachedData(
            $this->service->generatePng($registration),
            $this->service->filename($registration),
            ['mime' => 'image/png'],
        );
    }

    public function test_cancellation_email_has_no_qr_attachment(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration, EventRegistrationNotification::TYPE_CANCELLATION);

        $mail = new EventRegistrationMail($notification);

        $this->assertCount(0, $mail->attachments());
    }

    public function test_reactivation_email_has_qr_attachment(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration, EventRegistrationNotification::TYPE_REACTIVATION);

        $mail = new EventRegistrationMail($notification);

        $this->assertCount(1, $mail->attachments());
    }

    public function test_reminder_email_has_qr_attachment(): void
    {
        $registration = $this->registration();

        $notification = $this->notification($registration, EventRegistrationNotification::TYPE_REMINDER);

        $mail = new EventRegistrationMail($notification);

        $this->assertCount(1, $mail->attachments());
    }

    #[TestWith(['01a1d3fd-6958-7388-b0be-056a61855934'])]
    #[TestWith(['01a1d443-3077-700b-a715-ab693c48c829'])]
    #[TestWith(['01a1d44c-56a0-7239-a9e3-ab1e5b6d8118'])]
    #[TestWith(['01a1cf4f-9c62-721a-9b5a-d478aea18229'])]
    #[TestWith(['00000000-0000-7000-8000-000000000000'])]
    #[TestWith(['ffffffff-ffff-7fff-bfff-ffffffffffff'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab0'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab1'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab2'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab3'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab4'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab5'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab6'])]
    #[TestWith(['12345678-1234-7123-8123-123456789ab7'])]
    public function test_real_png_file_decodes_across_payload_patterns(string $uuid): void
    {
        $registration = EventRegistration::factory()->create(['uuid' => $uuid]);
        Storage::fake('local');
        $filename = $this->service->filename($registration);

        Storage::disk('local')->put($filename, $this->service->generatePng($registration));
        $reader = new QrReader(Storage::disk('local')->path($filename));
        $decoded = $reader->text(['TRY_HARDER' => true]);

        $this->assertSame('RA1:'.$uuid, $decoded, get_debug_type($reader->getError()).': '.$reader->getError()?->getTraceAsString());
    }
}
