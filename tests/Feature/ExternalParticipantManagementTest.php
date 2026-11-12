<?php

namespace Tests\Feature;

use App\Models\ExternalParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExternalParticipantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['dashboard.view', 'external-participants.view', 'external-participants.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function createUser(array $permissions = []): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function participantData(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => 'Budi Santoso',
                'email' => 'budi@example.com',
                'phone' => '081234567890',
                'organization' => 'PT Contoh Indonesia',
                'position' => 'Manager',
                'is_active' => true,
            ],
            $overrides,
        );
    }

    public function test_guest_cannot_access_external_participants(): void
    {
        $this->get(route('external-participants.index'))->assertRedirect(route('login'));
    }

    public function test_authorized_user_can_view_external_participants(): void
    {
        $user = $this->createUser(['external-participants.view']);

        ExternalParticipant::factory()->create([
            'name' => 'Budi Santoso',
        ]);

        $this->actingAs($user)->get(route('external-participants.index'))->assertOk()->assertSee('Budi Santoso');
    }

    public function test_user_without_permission_cannot_view_external_participants(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->get(route('external-participants.index'))->assertForbidden();
    }

    public function test_authorized_user_can_open_create_form(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $this->actingAs($user)->get(route('external-participants.create'))->assertOk()->assertSee('Tambah Peserta Eksternal');
    }

    public function test_authorized_user_can_create_external_participant(): void
    {
        $user = $this->createUser(['external-participants.view', 'external-participants.manage']);

        $this->actingAs($user)->post(route('external-participants.store'), $this->participantData())->assertRedirect(route('external-participants.index'));

        $this->assertDatabaseHas('external_participants', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'organization' => 'PT Contoh Indonesia',
            'is_active' => true,
        ]);
    }

    public function test_name_is_required(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $this->actingAs($user)
            ->post(
                route('external-participants.store'),
                $this->participantData([
                    'name' => '',
                ]),
            )
            ->assertSessionHasErrors('name');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $this->actingAs($user)
            ->post(
                route('external-participants.store'),
                $this->participantData([
                    'email' => 'invalid-email',
                ]),
            )
            ->assertSessionHasErrors('email');
    }

    public function test_contact_information_can_be_null(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $this->actingAs($user)
            ->post(
                route('external-participants.store'),
                $this->participantData([
                    'email' => null,
                    'phone' => null,
                    'organization' => null,
                    'position' => null,
                ]),
            )
            ->assertRedirect(route('external-participants.index'));

        $this->assertDatabaseHas('external_participants', [
            'name' => 'Budi Santoso',
            'email' => null,
            'phone' => null,
            'organization' => null,
            'position' => null,
        ]);
    }

    public function test_email_is_normalized_to_lowercase(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $this->actingAs($user)
            ->post(
                route('external-participants.store'),
                $this->participantData([
                    'email' => 'BUDI@EXAMPLE.COM',
                ]),
            )
            ->assertRedirect(route('external-participants.index'));

        $this->assertDatabaseHas('external_participants', [
            'email' => 'budi@example.com',
        ]);
    }

    public function test_multiple_participants_can_share_email(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        ExternalParticipant::factory()->create([
            'email' => 'contact@example.com',
        ]);

        $this->actingAs($user)
            ->post(
                route('external-participants.store'),
                $this->participantData([
                    'email' => 'contact@example.com',
                ]),
            )
            ->assertRedirect(route('external-participants.index'));

        $this->assertDatabaseCount('external_participants', 2);
    }

    public function test_authorized_user_can_open_edit_form(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $participant = ExternalParticipant::factory()->create();

        $this->actingAs($user)->get(route('external-participants.edit', $participant))->assertOk()->assertSee($participant->name);
    }

    public function test_authorized_user_can_update_external_participant(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $participant = ExternalParticipant::factory()->create();

        $this->actingAs($user)
            ->put(
                route('external-participants.update', $participant),
                $this->participantData([
                    'name' => 'Budi Updated',
                    'organization' => 'PT Mitra Nusantara',
                ]),
            )
            ->assertRedirect(route('external-participants.index'));

        $this->assertDatabaseHas('external_participants', [
            'id' => $participant->id,
            'name' => 'Budi Updated',
            'organization' => 'PT Mitra Nusantara',
        ]);
    }

    public function test_external_participant_can_be_deactivated(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $participant = ExternalParticipant::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(
                route('external-participants.update', $participant),
                $this->participantData([
                    'is_active' => false,
                ]),
            )
            ->assertRedirect(route('external-participants.index'));

        $this->assertDatabaseHas('external_participants', [
            'id' => $participant->id,
            'is_active' => false,
        ]);
    }

    public function test_user_without_permission_cannot_update_external_participant(): void
    {
        $user = $this->createUser();

        $participant = ExternalParticipant::factory()->create();

        $this->actingAs($user)->put(route('external-participants.update', $participant), $this->participantData())->assertForbidden();
    }

    public function test_authorized_user_can_delete_external_participant(): void
    {
        $user = $this->createUser(['external-participants.manage']);

        $participant = ExternalParticipant::factory()->create();

        $this->actingAs($user)->delete(route('external-participants.destroy', $participant))->assertRedirect(route('external-participants.index'));

        $this->assertDatabaseMissing('external_participants', [
            'id' => $participant->id,
        ]);
    }

    public function test_user_without_permission_cannot_delete_external_participant(): void
    {
        $user = $this->createUser();

        $participant = ExternalParticipant::factory()->create();

        $this->actingAs($user)->delete(route('external-participants.destroy', $participant))->assertForbidden();

        $this->assertDatabaseHas('external_participants', [
            'id' => $participant->id,
        ]);
    }

    public function test_inactive_user_cannot_access_external_participants(): void
    {
        $user = $this->createUser(['external-participants.view']);

        // Direct assignment avoids mass-assignment restrictions.
        $user->is_active = false;
        $user->save();

        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($user)->get(route('external-participants.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
