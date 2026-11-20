<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\RegistrationQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View as BladeView;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;
use Zxing\QrReader;

class EventInvitationExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['events.view', 'registrations.view', 'registrations.manage', 'attendance.scan', 'events.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_individual_invitation_is_an_a5_pdf_with_the_participants_details_and_scannable_qr(): void
    {
        $applicationName = 'Event & Attendance <Portal>';
        config(['app.name' => $applicationName]);
        $event = Event::factory()->published()->create([
            'title' => 'Forum & Kolaborasi', 'location' => 'Hotel <Kota>',
            'start_date' => '2026-11-16', 'end_date' => '2026-12-03',
        ]);
        $day = EventDay::factory()->for($event)->create(['date' => '2026-11-16']);
        $registration = EventRegistration::factory()->for($event)->invitedTo($day)
            ->for(Employee::factory()->create(['name' => 'Akmal <Utama>']))
            ->create(['registration_number' => 'REG-ABC/012']);
        EventRegistration::factory()->for($event)->for(Employee::factory()->create(['name' => 'Nama Peserta Lain']))->create();
        $rendered = [];
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$rendered): void {
            $rendered = $view->getData();
        });

        $response = $this->actingAs($this->staff('registrations.view', 'attendance.scan'))
            ->get($this->invitationUrl($event, $registration))->assertOk()
            ->assertDownload('undangan-forum-kolaborasi-reg-abc012.pdf')->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($registration->id, $rendered['registration']->id);
        $html = view('events.partials.invitation-document', $rendered)->render();
        $this->assertStringContainsString('<title>Undangan Forum &amp; Kolaborasi - '.e($applicationName).'</title>', $html);
        foreach (['PT ARI UTSAHA NUSANTARA', 'Forum &amp; Kolaborasi', 'Akmal &lt;Utama&gt;', 'Hotel &lt;Kota&gt;',
            '16 November 2026', 'REG-ABC/012', 'Tunjukkan QR Code ini kepada panitia saat check-in.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Nama Peserta Lain', $html);
        $this->assertStringNotContainsString('3 Desember 2026', $html);
        $this->assertStringNotContainsString('<Utama>', $html);
        $this->assertStringNotContainsString('<Kota>', $html);
        $payload = $this->invitationPayload($response->getContent());
        $this->assertSame('RA1:'.$registration->uuid, $payload);
        $this->postJson(route('attendance.scanner.validate'), [
            'event_id' => $event->id, 'event_day_id' => $day->id, 'qr_code' => $payload,
        ])->assertOk()->assertJsonPath('data.participant_name', 'Akmal <Utama>');
        $this->assertDatabaseCount('event_registrations', 2);
        $this->assertSame(EventRegistration::STATUS_REGISTERED, $registration->fresh()->status);
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_invitation_routes_require_authentication_and_their_respective_permissions(): void
    {
        $registration = EventRegistration::factory()->create();
        $individual = $this->invitationUrl($registration->event, $registration);
        $bulk = route('events.participants.invitations.export', $registration->event);

        $this->get($individual)->assertRedirect(route('login'));
        $this->get($bulk)->assertRedirect(route('login'));
        $this->actingAs($this->staff())->get($individual)->assertForbidden();
        $this->get($bulk)->assertForbidden();
        $this->actingAs($this->staff('registrations.view'))->get($bulk)->assertForbidden();
        $this->actingAs($this->staff('registrations.manage'))->get($individual)->assertForbidden();
        $this->actingAs($this->staff('registrations.view', 'registrations.manage')->revokePermissionTo('events.view'));
        $this->get($individual)->assertForbidden();
        $this->get($bulk)->assertForbidden();
    }

    public function test_individual_invitation_rejects_a_registration_from_another_event(): void
    {
        $registration = EventRegistration::factory()->create();

        $this->actingAs($this->staff('registrations.view'))
            ->get($this->invitationUrl(Event::factory()->create(), $registration))->assertNotFound();
    }

    public function test_cancelled_registration_returns_validation_feedback_instead_of_a_pdf(): void
    {
        $registration = EventRegistration::factory()->cancelled()->create();
        $url = $this->invitationUrl($registration->event, $registration);
        $message = 'Undangan hanya tersedia untuk registrasi yang masih aktif.';
        $this->actingAs($this->staff('registrations.view'));

        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors(['export' => $message]);
        $this->get($url)->assertRedirect(route('events.show', $registration->event).'#event-participants')
            ->assertSessionHasErrorsIn('invitations', ['export' => $message]);
    }

    #[TestWith(['all', ['employee', 'external']])]
    #[TestWith(['employee', ['employee']])]
    #[TestWith(['external', ['external']])]
    public function test_bulk_filters_export_only_the_expected_active_pdfs_with_unique_qr_codes(string $filter, array $expectedTypes): void
    {
        $event = Event::factory()->create(['title' => '../Forum Operasional']);
        $employee = EventRegistration::factory()->for($event)
            ->for(Employee::factory()->create(['name' => '../Putri Andini']))->create();
        $guest = ExternalParticipant::factory()->create(['name' => 'Putri Andini']);
        $external = EventRegistration::factory()->external()->for($event)
            ->create(['external_participant_id' => $guest->id]);
        EventRegistration::factory()->cancelled()->for($event)->create();
        EventRegistration::factory()->create();
        $registrations = ['employee' => $employee, 'external' => $external];

        $response = $this->actingAs($this->staff('registrations.manage'))->get(route('events.participants.invitations.export', [
            'event' => $event, 'participant_type' => $filter,
        ]))->assertOk()->assertDownload('forum-operasional-invitations.zip')->assertHeader('Content-Type', 'application/zip');

        $entries = $this->zipEntries($response);
        $this->assertCount(count($expectedTypes), $entries);
        foreach ($expectedTypes as $type) {
            $registration = $registrations[$type];
            $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($entries["undangan-forum-operasional-putri-andini-{$registration->id}.pdf"]));
        }
        $this->assertDatabaseCount('event_registrations', 4);
        $this->assertDatabaseCount('event_registration_notifications', 0);
    }

    public function test_bulk_export_validates_the_filter_and_handles_zero_matching_participants(): void
    {
        $event = Event::factory()->create();
        EventRegistration::factory()->for($event)->create();
        EventRegistration::factory()->external()->cancelled()->for($event)->create();
        $url = route('events.participants.invitations.export', $event);
        $this->actingAs($this->staff('registrations.manage'));

        $this->getJson($url.'?participant_type=bad')->assertUnprocessable()
            ->assertJsonValidationErrors(['participant_type' => 'Pilih semua peserta, Employee, atau External.']);
        $this->getJson($url.'?participant_type=external')->assertUnprocessable()
            ->assertJsonValidationErrors(['export' => 'Tidak ada peserta aktif untuk filter undangan ini.']);
    }

    public function test_bulk_export_spans_batches_and_loads_employee_details_once_per_batch(): void
    {
        $event = Event::factory()->create();
        EventRegistration::factory()->for($event)->count(51)->create();
        $this->actingAs($this->staff('registrations.manage'));
        DB::enableQueryLog();

        try {
            $response = $this->get(route('events.participants.invitations.export', $event))->assertOk();
            $employeeQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "employees"'));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(51, $this->zipEntries($response));
        $this->assertCount(2, $employeeQueries);
    }

    public function test_bulk_export_reports_a_failed_pdf_without_returning_a_partial_zip(): void
    {
        $event = Event::factory()->create();
        [$first, $second] = EventRegistration::factory()->for($event)->count(2)->create();
        $qrCode = app(RegistrationQrCodeService::class);
        $failure = new RuntimeException('QR generator unavailable');
        Exceptions::fake();
        $this->mock(RegistrationQrCodeService::class, function ($mock) use ($first, $second, $qrCode, $failure): void {
            $mock->shouldReceive('generatePng')->once()->withArgs(fn (EventRegistration $row): bool => $row->is($first))
                ->andReturn($qrCode->generatePng($first));
            $mock->shouldReceive('generatePng')->once()->withArgs(fn (EventRegistration $row): bool => $row->is($second))
                ->andThrow($failure);
        });

        $this->actingAs($this->staff('registrations.manage'))->getJson(route('events.participants.invitations.export', $event))
            ->assertInternalServerError()->assertJsonValidationErrors(['export' => "Undangan untuk registrasi {$second->registration_number} gagal dibuat."]);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $failure);
    }

    public function test_participants_tab_shows_invitation_actions_counts_and_employee_picker_for_the_right_users(): void
    {
        $this->withoutVite();
        $event = Event::factory()->create(['status' => Event::STATUS_DRAFT]);
        $registration = EventRegistration::factory()->for($event)->create();
        EventRegistration::factory()->external()->for($event)->create();
        $cancelled = EventRegistration::factory()->for($event)->cancelled()->create();
        $page = route('events.show', $event);

        $this->actingAs($this->staff())->get($page)->assertOk()
            ->assertDontSee('Download Invitation PDF')->assertDontSee('Export Invitations');
        $this->actingAs($this->staff('registrations.view'))->get($page)->assertOk()
            ->assertSee('aria-label="Download Invitation PDF"', false)->assertSee('title="Download Invitation PDF"', false)
            ->assertSee($this->invitationUrl($event, $registration), false)->assertDontSee($this->invitationUrl($event, $cancelled), false)
            ->assertDontSee('Export Invitations');
        $this->actingAs($this->staff('registrations.manage', 'events.update'))->get($page)->assertOk()
            ->assertSee('Export Invitations')->assertSee('Semua peserta (2)')->assertSee('Employee (1)')->assertSee('External (1)')
            ->assertSee('data-tom-select-dropdown-parent', false)->assertSee('name="participant_ids[]"', false)
            ->assertSee('aria-label="Batalkan Registrasi"', false)->assertSee('aria-label="Aktifkan Kembali Registrasi"', false);
    }

    private function invitationUrl(Event $event, EventRegistration $registration): string
    {
        return route('events.participants.invitation', [$event, $registration]);
    }

    private function staff(string ...$permissions): User
    {
        return User::factory()->create(['is_active' => true])->givePermissionTo('events.view', ...$permissions);
    }

    /** @return array<string, string> */
    private function zipEntries(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertSame((string) strlen($content), $response->headers->get('Content-Length'));
        $path = tempnam(sys_get_temp_dir(), 'invitation-test-');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
            $files = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $filename = $zip->getNameIndex($index);
                $this->assertArrayNotHasKey($filename, $files);
                $files[$filename] = $zip->getFromIndex($index);
            }
            $zip->close();

            return $files;
        } finally {
            unlink($path);
        }
    }

    private function invitationPayload(string $pdf): string
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0\S*\s+0\S*\s+419\.\d+\s+595\.\d+\s*\]/', $pdf);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));

        // Dompdf embeds the RGB image as a PDF stream using PNG row predictors.
        $this->assertSame(1, preg_match('~/Subtype /Image\s+/Width (\d+)\s+/Height (\d+)(?:(?!endobj).)*?/ColorSpace /DeviceRGB\s+/BitsPerComponent 8\s+/Length (\d+)\s*>>\s*stream\r?\n~s', $pdf, $image, PREG_OFFSET_CAPTURE));
        $streamOffset = $image[0][1] + strlen($image[0][0]);
        $imageData = substr($pdf, $streamOffset, (int) $image[3][0]);
        $png = "\x89PNG\r\n\x1a\n".$this->pngChunk('IHDR', pack('NNCCCCC', (int) $image[1][0], (int) $image[2][0], 8, 2, 0, 0, 0))
            .$this->pngChunk('IDAT', $imageData).$this->pngChunk('IEND', '');

        return (new QrReader($png, QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]);
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
