<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FlashNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_application_layout_exposes_all_flash_types_as_valid_json(): void
    {
        $messages = [
            'success' => 'Departemen berhasil ditambahkan.',
            'error' => 'Departemen tidak dapat dihapus.',
            'warning' => 'Periksa kembali data Anda.',
            'info' => 'Informasi terbaru tersedia.',
        ];

        $response = $this->actingAs($this->authorizedUser(['dashboard.view']))
            ->withSession($messages)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame($messages, $this->notificationData($response));
    }

    public function test_flash_messages_preserve_quotes_unicode_and_html_as_text(): void
    {
        $message = 'Departemen "R&D" <IT> — berhasil. </script><script>window.flashInjected = true</script>';

        $response = $this->actingAs($this->authorizedUser(['dashboard.view']))
            ->withSession(['success' => $message])
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame($message, $this->notificationData($response)['success']);
        $response->assertDontSee('<script>window.flashInjected = true</script>', false);
    }

    public function test_authentication_layout_includes_flash_notifications(): void
    {
        $response = $this->withSession(['error' => 'Akun Anda telah dinonaktifkan.'])
            ->get(route('login'))
            ->assertOk();

        $this->assertSame('Akun Anda telah dinonaktifkan.', $this->notificationData($response)['error']);
    }

    public function test_department_creation_flashes_success_on_the_redirected_page_once(): void
    {
        $this->actingAs($this->authorizedUser(['departments.create', 'departments.view']))
            ->post(route('departments.store'), [
                'code' => 'IT',
                'name' => 'Information Technology',
                'is_active' => true,
            ])
            ->assertRedirectToRoute('departments.index')
            ->assertSessionHas('success', 'Departemen berhasil ditambahkan.');

        $response = $this->get(route('departments.index'))->assertOk();

        $this->assertSame('Departemen berhasil ditambahkan.', $this->notificationData($response)['success']);

        $nextResponse = $this->get(route('departments.index'))->assertOk();

        $this->assertNull($this->notificationData($nextResponse)['success']);
    }

    private function authorizedUser(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function notificationData(TestResponse $response): array
    {
        $this->assertSame(1, preg_match(
            '/<script\b[^>]*\bid="flash-notifications"[^>]*>(.*?)<\/script>/s',
            $response->getContent(),
            $matches,
        ));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
