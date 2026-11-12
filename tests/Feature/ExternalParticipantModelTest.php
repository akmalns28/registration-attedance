<?php

namespace Tests\Feature;

use App\Models\ExternalParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalParticipantModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_participant_can_be_created(): void
    {
        $participant = ExternalParticipant::factory()->create([
            'name' => 'Budi Santoso',
            'organization' => 'PT Contoh Indonesia',
        ]);

        $this->assertDatabaseHas('external_participants', [
            'id' => $participant->id,
            'name' => 'Budi Santoso',
            'organization' => 'PT Contoh Indonesia',
        ]);
    }

    public function test_external_participant_is_active_by_default(): void
    {
        $participant = ExternalParticipant::create([
            'name' => 'Budi Santoso',
        ]);

        $this->assertTrue($participant->fresh()->is_active);
    }

    public function test_active_scope_only_returns_active_participants(): void
    {
        ExternalParticipant::factory()->create([
            'is_active' => true,
        ]);

        ExternalParticipant::factory()->inactive()->create();

        $this->assertCount(1, ExternalParticipant::active()->get());
    }

    public function test_inactive_scope_only_returns_inactive_participants(): void
    {
        ExternalParticipant::factory()->create([
            'is_active' => true,
        ]);

        ExternalParticipant::factory()->inactive()->create();

        $this->assertCount(1, ExternalParticipant::inactive()->get());
    }

    public function test_participant_can_be_created_without_contact_information(): void
    {
        $participant = ExternalParticipant::create([
            'name' => 'Peserta Tanpa Kontak',
        ]);

        $this->assertNull($participant->email);
        $this->assertNull($participant->phone);
        $this->assertNull($participant->organization);
    }

    public function test_organization_scope_filters_participants(): void
    {
        ExternalParticipant::factory()->create([
            'organization' => 'PT Contoh Indonesia',
        ]);

        ExternalParticipant::factory()->create([
            'organization' => 'PT Mitra Nusantara',
        ]);

        $this->assertCount(1, ExternalParticipant::organization('PT Contoh Indonesia')->get());
    }

    public function test_multiple_participants_can_share_contact_information(): void
    {
        ExternalParticipant::factory()->create([
            'email' => 'contact@example.com',
            'phone' => '081234567890',
        ]);

        ExternalParticipant::factory()->create([
            'email' => 'contact@example.com',
            'phone' => '081234567890',
        ]);

        $this->assertDatabaseCount('external_participants', 2);
    }
}
