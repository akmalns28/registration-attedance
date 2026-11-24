<?php

namespace Tests\Feature;

use App\Mail\EventRegistrationMail;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\RegistrationQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View as BladeView;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
        $day = EventDay::factory()->for($event)->create(['date' => '2026-11-16', 'title' => 'Hidden Day Title']);
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
        $this->assertStringNotContainsString('Hidden Day Title', $html);
        $this->assertStringNotContainsString('3 Desember 2026', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('Klik lokasi untuk membuka Google Maps', $html);
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pdf_embeds_the_event_logo_and_maps_link(): void
    {
        Storage::fake('local');
        config(['event_invitations.memory_limit_mb' => 96]);
        $logoPath = UploadedFile::fake()->image('logo.jpg', 70, 40)->store('event-logos', 'local');
        $url = 'https://maps.google.com/?q=Kota';
        $event = Event::factory()->create(['logo_path' => $logoPath, 'google_maps_url' => $url, 'location' => 'Hotel Kota']);
        $registration = EventRegistration::factory()->for($event)->invitedTo()->create();
        $rendered = [];
        $previousLimit = ini_get('memory_limit');
        $appliedLimit = 0;
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$rendered, &$appliedLimit): void {
            $rendered = $view->getData();
            $appliedLimit = ini_parse_quantity(ini_get('memory_limit'));
        });

        $response = $this->actingAs($this->staff('registrations.view'))->get($this->invitationUrl($event, $registration))->assertOk();
        $this->assertGreaterThan(0, $appliedLimit);
        $this->assertLessThanOrEqual(96 * 1024 * 1024, $appliedLimit);
        $this->assertSame($previousLimit, ini_get('memory_limit'));
        $this->assertStringStartsWith('data:image/jpeg;base64,', $rendered['logoImage']);
        $this->assertStringContainsString('Klik lokasi untuk membuka Google Maps', view('events.partials.invitation-document', $rendered)->render());
        $this->assertStringContainsString('/URI ('.$url.')', $response->getContent());
        $this->assertStringContainsString('/DCTDecode', $response->getContent());
        $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($response->getContent()));
    }

    #[TestWith([[4], '4 Februari 2027'])]
    #[TestWith([[4, 3, 5], '3 Februari 2027 – 5 Februari 2027'])]
    #[TestWith([[8, 4, 3], '3 Februari 2027, 4 Februari 2027, 8 Februari 2027'])]
    public function test_email_and_pdf_show_only_the_assigned_dates_in_chronological_order(array $assignedDates, string $expected): void
    {
        $event = Event::factory()->create(['start_date' => '2027-02-01', 'end_date' => '2027-02-10']);
        $days = [];
        foreach ($assignedDates as $index => $date) {
            $days[] = EventDay::factory()->for($event)->create([
                'date' => sprintf('2027-02-%02d', $date), 'day_number' => $index + 1, 'title' => 'Hidden Day Title',
            ]);
        }

        $registration = EventRegistration::factory()->for($event)->invitedTo(...$days)->create();
        $notification = EventRegistrationNotification::factory()->for($registration, 'registration')->create([
            'event_id' => $event->id, 'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
        ]);
        $rendered = [];
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$rendered): void {
            $rendered = $view->getData();
        });
        $this->actingAs($this->staff('registrations.view'))->get($this->invitationUrl($event, $registration))->assertOk();

        foreach ([view('events.partials.invitation-document', $rendered)->render(), (new EventRegistrationMail($notification))->render()] as $html) {
            $this->assertStringContainsString($expected, $html);
            $this->assertStringNotContainsString('Hidden Day Title', $html);
            $this->assertStringNotContainsString('1 Februari 2027', $html);
            $this->assertStringNotContainsString('10 Februari 2027', $html);
        }
    }

    public function test_download_and_bulk_export_reject_registrations_without_any_active_assignments(): void
    {
        $event = Event::factory()->create();
        $inactiveDay = EventDay::factory()->for($event)->inactive()->create();
        $revokedDay = EventDay::factory()->for($event)->create(['day_number' => 2, 'date' => '2027-01-16']);
        $registration = EventRegistration::factory()->for($event)->invitedTo($inactiveDay, $revokedDay)->create();
        $registration->invitedDays()->updateExistingPivot($revokedDay->id, ['revoked_at' => now()]);
        $this->actingAs($this->staff('registrations.view', 'registrations.manage'));

        $this->getJson($this->invitationUrl($event, $registration))->assertUnprocessable()
            ->assertJsonValidationErrors(['export' => 'Peserta belum memiliki hari undangan aktif.']);
        $this->getJson(route('events.participants.invitations.export', $event))->assertUnprocessable()
            ->assertJsonValidationErrors(['export' => 'Tidak ada peserta dengan hari undangan aktif untuk filter ini.']);
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
        $event = Event::factory()->create(['title' => '../Forum Operasional', 'start_date' => '2027-02-01', 'end_date' => '2027-02-03']);
        $firstDay = EventDay::factory()->for($event)->create(['date' => '2027-02-01']);
        $lastDay = EventDay::factory()->for($event)->create(['day_number' => 3, 'date' => '2027-02-03']);
        $employee = EventRegistration::factory()->for($event)->invitedTo($firstDay)
            ->for(Employee::factory()->create(['name' => '../Putri Andini']))->create();
        $guest = ExternalParticipant::factory()->create(['name' => 'Putri Andini', 'email' => null, 'phone' => null]);
        $external = EventRegistration::factory()->external()->for($event)->invitedTo($firstDay, $lastDay)
            ->create(['external_participant_id' => $guest->id]);
        EventRegistration::factory()->cancelled()->for($event)->invitedTo($firstDay)->create();
        EventRegistration::factory()->for($event)->create();
        EventRegistration::factory()->create();
        $registrations = ['employee' => $employee, 'external' => $external];
        $renderedDates = [];
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$renderedDates): void {
            $data = $view->getData();
            $renderedDates[$data['registration']->id] = $data['invitedDates'];
        });

        $response = $this->actingAs($this->staff('registrations.manage'))->get(route('events.participants.invitations.export', [
            'event' => $event, 'participant_type' => $filter,
        ]))->assertOk()->assertDownload('forum-operasional-invitations.zip')->assertHeader('Content-Type', 'application/zip');

        $entries = $this->zipEntries($response);
        $this->assertCount(count($expectedTypes), $entries);
        foreach ($expectedTypes as $type) {
            $registration = $registrations[$type];
            $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($entries["undangan-forum-operasional-putri-andini-{$registration->id}.pdf"]));
            $this->assertSame($type === 'employee' ? '1 Februari 2027' : '1 Februari 2027, 3 Februari 2027', $renderedDates[$registration->id]);
        }
        $this->assertDatabaseCount('event_registrations', 5);
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
            ->assertJsonValidationErrors(['export' => 'Tidak ada peserta dengan hari undangan aktif untuk filter ini.']);
    }

    #[TestWith([50, 1])]
    #[TestWith([100, 2])]
    #[TestWith([300, 6])]
    public function test_bulk_export_limits_rendering_memory_with_a_full_size_logo(int $count, int $batches): void
    {
        Storage::fake('local');
        $logo = imagecreatetruecolor(3000, 2250);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 30, 107, 196, 60));
        $logoPath = 'event-logos/large.png';
        Storage::disk('local')->makeDirectory('event-logos');
        imagepng($logo, Storage::disk('local')->path($logoPath));
        unset($logo);

        $event = Event::factory()->create(['title' => 'Forum Operasional', 'logo_path' => $logoPath]);
        $day = EventDay::factory()->for($event)->create();
        $employees = EventRegistration::factory()->for($event)->invitedTo($day)->count(intdiv($count, 2))->create();
        $guests = EventRegistration::factory()->external()->for($event)->invitedTo($day)->count(intdiv($count, 2))->create();
        $this->actingAs($this->staff('registrations.manage'));
        $previousLimit = ini_get('memory_limit');
        DB::enableQueryLog();
        gc_collect_cycles();
        $baselineMemory = memory_get_usage(true);
        memory_reset_peak_usage();

        try {
            $response = $this->get(route('events.participants.invitations.export', ['event' => $event, 'participant_type' => 'all']))->assertOk();
            $peakGrowth = memory_get_peak_usage(true) - $baselineMemory;
            $employeeQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "employees"'));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($previousLimit, ini_get('memory_limit'));
        $this->assertLessThan(64 * 1024 * 1024, $peakGrowth, 'Peak PHP memory growth during ZIP generation');
        $entries = $this->zipEntries($response);
        $this->assertCount($count, $entries);
        // Employees are inserted first, so only their half of the lookup batches query employees.
        $this->assertCount((int) ceil($batches / 2), $employeeQueries);
        $entriesByRegistration = [];
        foreach ($entries as $filename => $pdf) {
            $this->assertMatchesRegularExpression('/^undangan-forum-operasional-.*-\d+\.pdf$/', $filename);
            $registrationId = (int) str($filename)->beforeLast('.pdf')->afterLast('-')->value();
            $entriesByRegistration[$registrationId] = $pdf;
            $this->assertInvitationPdf($pdf);
        }
        $this->assertSame([...$employees->modelKeys(), ...$guests->modelKeys()], array_keys($entriesByRegistration));
        foreach ([$employees->first(), $guests->first(), $guests->last()] as $registration) {
            $pdf = $entriesByRegistration[$registration->id];
            $this->assertMatchesRegularExpression('~/Subtype /Image\s+/Width 256\s+/Height 192\b~', $pdf);
            $this->assertStringContainsString('/SMask ', $pdf);
            $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($pdf));
        }
    }

    public function test_bulk_export_reports_a_failed_pdf_without_returning_a_partial_zip(): void
    {
        $event = Event::factory()->create();
        [$first, $second] = EventRegistration::factory()->for($event)->invitedTo()->count(2)->create();
        $qrCode = app(RegistrationQrCodeService::class);
        $failure = new RuntimeException('QR generator unavailable');
        $previousLimit = ini_get('memory_limit');
        Exceptions::fake();
        $this->mock(RegistrationQrCodeService::class, function ($mock) use ($first, $second, $qrCode, $failure): void {
            $mock->shouldReceive('generateInvitationPng')->once()->withArgs(fn (EventRegistration $row): bool => $row->is($first))
                ->andReturn($qrCode->generateInvitationPng($first));
            $mock->shouldReceive('generateInvitationPng')->once()->withArgs(fn (EventRegistration $row): bool => $row->is($second))
                ->andThrow($failure);
        });

        $this->actingAs($this->staff('registrations.manage'))->getJson(route('events.participants.invitations.export', $event))
            ->assertInternalServerError()->assertJsonValidationErrors(['export' => "Undangan untuk registrasi {$second->registration_number} gagal dibuat."]);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $failure);
        $this->assertSame($previousLimit, ini_get('memory_limit'));
    }

    public function test_participants_tab_shows_invitation_actions_counts_and_participant_picker_for_the_right_users(): void
    {
        $this->withoutVite();
        $event = Event::factory()->create(['status' => Event::STATUS_DRAFT]);
        $registration = EventRegistration::factory()->for($event)->invitedTo()->create();
        EventRegistration::factory()->external()->for($event)->invitedTo()->create();
        EventRegistration::factory()->for($event)->create();
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
            ->assertSee('id="addParticipantsModal"', false)->assertSee('Register Participants')
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
        $this->assertInvitationPdf($pdf);

        // The opaque QR is embedded as an RGB stream with PNG row predictors.
        $this->assertSame(1, preg_match('~/Subtype /Image\s+/Width (256)\s+/Height (256)(?:(?!endobj).)*?/ColorSpace /DeviceRGB\s+/BitsPerComponent 8\s+/Length (\d+)\s*>>\s*stream\r?\n~s', $pdf, $image, PREG_OFFSET_CAPTURE));
        $streamOffset = $image[0][1] + strlen($image[0][0]);
        $imageData = substr($pdf, $streamOffset, (int) $image[3][0]);
        $png = "\x89PNG\r\n\x1a\n".$this->pngChunk('IHDR', pack('NNCCCCC', (int) $image[1][0], (int) $image[2][0], 8, 2, 0, 0, 0))
            .$this->pngChunk('IDAT', $imageData).$this->pngChunk('IEND', '');

        return (new QrReader($png, QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]);
    }

    private function assertInvitationPdf(string $pdf): void
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0\S*\s+0\S*\s+419\.\d+\s+595\.\d+\s*\]/', $pdf);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));

    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
