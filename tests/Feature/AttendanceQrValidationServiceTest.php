<?php

namespace Tests\Feature;

use App\Models\EventRegistration;
use App\Services\AttendanceQrValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceQrValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceQrValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AttendanceQrValidationService::class);
    }

    public function test_valid_qr_resolves_registration(): void
    {
        $registration = EventRegistration::factory()->create();

        $result = $this->service->resolve('RA1:' . $registration->uuid);

        $this->assertSame($registration->id, $result->id);
    }

    public function test_invalid_prefix_is_rejected(): void
    {
        $registration = EventRegistration::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->resolve('INVALID:' . $registration->uuid);
    }

    public function test_url_payload_is_rejected(): void
    {
        $registration = EventRegistration::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->resolve('http://localhost:8000/registrations/' . $registration->uuid);
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->resolve('RA1:not-a-valid-uuid');
    }

    public function test_unknown_registration_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->resolve('RA1:01999999-9999-7999-8999-999999999999');
    }

    public function test_empty_payload_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->resolve('');
    }
}
