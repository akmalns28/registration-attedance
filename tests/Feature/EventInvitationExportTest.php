<?php

namespace Tests\Feature;

use App\Jobs\GenerateEventInvitationExport;
use App\Mail\EventRegistrationMail;
use App\Models\Employee;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventInvitationExport;
use App\Models\EventPoster;
use App\Models\EventRegistration;
use App\Models\EventRegistrationNotification;
use App\Models\EventSchedule;
use App\Models\ExternalParticipant;
use App\Models\User;
use App\Services\EventInvitationExportQueue;
use App\Services\EventInvitationExportService;
use App\Services\EventPosterService;
use App\Services\RegistrationQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
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
            $rendered = $view->getData() + $view->getData()['pages'][0];
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
            $rendered = $view->getData() + $view->getData()['pages'][0];
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

    #[TestWith([[4], ['4 Februari 2027']])]
    #[TestWith([[4, 3, 5], ['3 Februari 2027', '4 Februari 2027', '5 Februari 2027']])]
    #[TestWith([[8, 4, 3], ['3 Februari 2027', '4 Februari 2027', '8 Februari 2027']])]
    public function test_email_and_pdf_show_only_the_assigned_dates_in_chronological_order(array $assignedDates, array $expected): void
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
            $rendered = $view->getData() + $view->getData()['pages'][0];
        });
        $this->actingAs($this->staff('registrations.view'))->get($this->invitationUrl($event, $registration))->assertOk();

        foreach ([view('events.partials.invitation-document', $rendered)->render(), (new EventRegistrationMail($notification))->render()] as $html) {
            foreach ($expected as $date) {
                $this->assertSame(1, substr_count($html, $date), $date);
            }
            $positions = array_map(fn (string $date): int => strpos($html, $date), $expected);
            $this->assertSame($positions, collect($positions)->sort()->values()->all());
            $this->assertSame(1, substr_count($html, 'Jadwal Kehadiran'));
            $this->assertSame(1, substr_count($html, e($registration->participant_name)));
            $this->assertStringNotContainsString('Yth.', $html);
            $this->assertStringNotContainsString('Anda telah terdaftar sebagai peserta', $html);
            $this->assertStringNotContainsString('Dress Code', $html);
            $this->assertStringNotContainsString('Hidden Day Title', $html);
            $this->assertStringNotContainsString('1 Februari 2027', $html);
            $this->assertStringNotContainsString('10 Februari 2027', $html);
        }
    }

    #[TestWith([null, 1])]
    #[TestWith(['Seragam olahraga', 2])]
    public function test_resent_email_and_pdf_share_day_times_and_effective_dress_codes(?string $override, int $dressCodeLabels): void
    {
        $event = Event::factory()->create(['start_date' => '2026-12-02', 'end_date' => '2026-12-05', 'dress_code' => 'Batik']);
        [$first, $second, , $inactive] = EventDay::factory()->for($event)->count(4)->sequence(
            ['day_number' => 1, 'date' => '2026-12-02', 'start_time' => '08:00:00', 'end_time' => '17:00:00'],
            ['day_number' => 2, 'date' => '2026-12-03', 'start_time' => '09:00:00', 'dress_code' => $override],
            ['day_number' => 3, 'date' => '2026-12-04', 'end_time' => '16:00:00'],
            ['day_number' => 4, 'date' => '2026-12-05', 'is_active' => false],
        )->create(['title' => 'Internal Day Title']);
        EventSchedule::factory()->for($first)->create(['is_active' => true, 'title' => 'Internal Rundown']);
        $registration = EventRegistration::factory()->external()->for($event)->invitedTo($second, $first, $inactive)->create();
        $notification = EventRegistrationNotification::factory()->for($registration, 'registration')->create([
            'event_id' => $event->id, 'notification_type' => EventRegistrationNotification::TYPE_INVITATION,
            'metadata' => ['bulk_resend' => true],
        ]);
        $rendered = [];
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$rendered): void {
            $rendered = $view->getData();
        });
        $this->actingAs($this->staff('registrations.view'))->get($this->invitationUrl($event, $registration))->assertOk();
        foreach ([view('events.partials.invitation-document', $rendered)->render(), (new EventRegistrationMail($notification))->render()] as $html) {
            $this->assertStringContainsString('2 Desember 2026 | 08.00–17.00 WIB', $html);
            $this->assertStringContainsString('3 Desember 2026 | Mulai 09.00 WIB', $html);
            $this->assertSame(1, substr_count($html, '2 Desember 2026'));
            $this->assertSame(1, substr_count($html, '3 Desember 2026'));
            $this->assertSame($dressCodeLabels, substr_count($html, 'Dress Code'));
            $this->assertSame(1, substr_count($html, 'Batik'));
            $this->assertSame($override === null ? 0 : 1, substr_count($html, 'Seragam olahraga'));
            foreach (['Internal Day Title', 'Internal Rundown', '4 Desember 2026', '5 Desember 2026', 'Jadwal Event', 'Hari Undangan', 'External'] as $text) {
                $this->assertStringNotContainsString($text, $html);
            }
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
            foreach ($view->getData()['pages'] as $page) {
                $renderedDates[$page['registration']->id] = array_column($page['attendance']['days'], 'date');
            }
        });

        $response = $this->actingAs($this->staff('registrations.manage'))->get(route('events.participants.invitations.export', [
            'event' => $event, 'participant_type' => $filter,
        ]))->assertOk()->assertDownload('forum-operasional-invitations.pdf')->assertHeader('Content-Type', 'application/pdf');

        $pdf = $response->getContent();
        $this->assertInvitationPdf($pdf, count($expectedTypes));
        foreach ($expectedTypes as $index => $type) {
            $registration = $registrations[$type];
            $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($pdf, $index));
            $this->assertSame($type === 'employee' ? ['1 Februari 2027'] : ['1 Februari 2027', '3 Februari 2027'], $renderedDates[$registration->id]);
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

    public function test_invalid_registration_relationships_cannot_export_an_invitation(): void
    {
        $event = Event::factory()->create();
        $day = EventDay::factory()->for($event)->create();
        $otherDay = EventDay::factory()->create();
        $invalid = EventRegistration::factory()->for($event)->invitedTo($day)->create(['employee_id' => null]);
        $wrongDay = EventRegistration::factory()->for($event)->create();
        $wrongDay->invitedDays()->attach($otherDay);
        $this->actingAs($this->staff('registrations.manage', 'registrations.view'));

        $this->getJson($this->invitationUrl($event, $invalid))->assertUnprocessable()
            ->assertJsonValidationErrors(['export' => 'Data peserta untuk undangan tidak ditemukan.']);
        $this->getJson($this->invitationUrl($event, $wrongDay))->assertUnprocessable()
            ->assertJsonValidationErrors(['export' => 'Peserta belum memiliki hari undangan aktif.']);
        $this->getJson(route('events.participants.invitations.export', $event))->assertUnprocessable()
            ->assertJsonValidationErrors(['export' => 'Tidak ada peserta dengan hari undangan aktif untuk filter ini.']);
    }

    #[TestWith([1])]
    #[TestWith([5])]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_direct_export_threshold_renders_branded_pdfs_within_web_limits(int $count): void
    {
        Storage::fake('local');
        $logo = UploadedFile::fake()->image('logo.png', 2800, 2100)->store('event-logos', 'local');
        $event = Event::factory()->create(['description' => "Baris pertama <script>\nBaris kedua", 'logo_path' => $logo]);
        $posters = app(EventPosterService::class);
        $posters->saveUploads([UploadedFile::fake()->image('cover.jpg', 2400, 3600)],
            fn (array $uploads): array => $posters->sync($event, $uploads, []));
        EventPoster::factory()->for($event)->create();
        $days = [$event->days()->create(['date' => $event->start_date, 'day_number' => 1, 'is_active' => true])];
        $registrations = EventRegistration::factory()->for($event)->invitedTo(...$days)->count($count)->create();
        $registrations->last()->employee->update(['name' => 'Kasiran Lukman Pradana S.H.']);

        $rendered = [];
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$rendered): void {
            $rendered = $view->getData();
        });
        $this->actingAs($this->staff('registrations.manage'));
        $started = hrtime(true);
        memory_reset_peak_usage();
        $response = $this->get(route('events.participants.invitations.export', $event))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $duration = (hrtime(true) - $started) / 1e9;

        $this->assertLessThan(30, $duration);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
        $this->assertInvitationPdf($response->getContent(), $count);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([0 => 'Baris pertama <script>', 1 => 'Baris kedua'], $rendered['description']);
        $poster = $event->primaryPoster()->sole();
        $variant = EventPoster::variantPath($poster->file_path, 'invitation');
        $pagePoster = $rendered['pages'][0]['poster'];
        $this->assertSame(Storage::disk('local')->path($variant), $pagePoster['image']);
        $posterBytes = Storage::disk('local')->get($variant);
        $this->assertSame([640, 960, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($posterBytes), 0, 3));
        $this->assertLessThan(512 * 1024, strlen($posterBytes));
        $this->assertMatchesRegularExpression('~/Subtype /Image\s+/Width 640\s+/Height 960\b~', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('~/Subtype /Image\s+/Width 2400\s+/Height 3600\b~', $response->getContent());
        $this->assertEqualsWithDelta(2 / 3, $pagePoster['width'] / $pagePoster['height'], 0.01);
        $html = view('events.partials.invitation-document', $rendered)->render();
        $this->assertStringContainsString('Baris pertama &lt;script&gt;', $html);
        $this->assertStringNotContainsString('Jenis Peserta', $html);
    }

    public function test_individual_and_batched_invitations_choose_one_poster_per_participants_invited_days(): void
    {
        $disk = Storage::fake('local');
        $event = Event::factory()->create(['start_date' => '2027-01-15', 'end_date' => '2027-01-17']);
        [$firstDay, $secondDay, $thirdDay] = EventDay::factory()->for($event)->count(3)->sequence(
            ['day_number' => 1, 'date' => '2027-01-15'],
            ['day_number' => 2, 'date' => '2027-01-16'],
            ['day_number' => 3, 'date' => '2027-01-17'],
        )->create();
        $firstPoster = EventPoster::factory()->for($event)->primary()->hasAttached($firstDay, [], 'days')->create(['sort_order' => 2]);
        $secondPoster = EventPoster::factory()->for($event)->hasAttached($secondDay, [], 'days')->create(['sort_order' => 0]);
        $sharedPoster = EventPoster::factory()->for($event)->hasAttached(collect([$firstDay, $secondDay]), [], 'days')->create(['sort_order' => 1]);
        $images = [];
        foreach ([$firstPoster, $secondPoster, $sharedPoster] as $index => $poster) {
            $variant = EventPoster::variantPath($poster->file_path, 'invitation');
            $file = UploadedFile::fake()->image('poster.jpg', 105 + $index, 160);
            $disk->put($variant, file_get_contents($file->getPathname()));
            $images[] = $disk->path($variant);
        }

        $participants = [
            EventRegistration::factory()->for($event)->invitedTo($firstDay)->create(),
            EventRegistration::factory()->external()->for($event)->invitedTo($secondDay)->create(),
            EventRegistration::factory()->for($event)->invitedTo($firstDay, $secondDay)->create(),
            EventRegistration::factory()->for($event)->invitedTo($thirdDay)->create(),
        ];
        $expected = [$images[0], $images[1], $images[0], null];
        $rendered = [];
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$rendered): void {
            foreach ($view->getData()['pages'] as $page) {
                $rendered[$page['registration']->id] = $page['poster']['image'] ?? null;
            }
        });
        $this->actingAs($this->staff('registrations.view', 'registrations.manage'));
        foreach ($participants as $index => $participant) {
            $pdf = $this->get($this->invitationUrl($event, $participant))->assertOk()->getContent();
            $this->assertSame($expected[$index], $rendered[$participant->id]);
            $this->assertSame('RA1:'.$participant->uuid, $this->invitationPayload($pdf));
        }

        $url = route('events.participants.invitations.export', $event);
        $rendered = [];
        $pdf = $this->get($url)->assertOk()->getContent();
        $this->assertInvitationPdf($pdf, 4);
        $this->assertSame($expected, array_values($rendered));
        $this->assertMatchesRegularExpression('~/Subtype /Image\s+/Width 105\s+/Height 160\b~', $pdf);
        $this->assertMatchesRegularExpression('~/Subtype /Image\s+/Width 106\s+/Height 160\b~', $pdf);
        $this->assertDoesNotMatchRegularExpression('~/Subtype /Image\s+/Width 107\s+/Height 160\b~', $pdf);

        config(['event_invitations.direct_limit' => 1, 'event_invitations.batch_size' => 2]);
        $rendered = [];
        $export = $this->postJson($url, ['participant_type' => 'all'])->assertAccepted()->json();
        $this->workOneExportJob();
        $this->workOneExportJob();
        $this->workOneExportJob();
        $this->getJson($export['status_url'])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame($expected, array_values($rendered));
    }

    public function test_external_invitation_preview_keeps_qr_and_only_the_primary_poster_with_no_contact_details(): void
    {
        Storage::fake('local');
        $event = Event::factory()->create(['description' => "Agenda <umum>\nBawa undangan"]);
        $path = UploadedFile::fake()->image('poster.png', 240, 320)->store('event-posters', 'local');
        EventPoster::factory()->for($event)->primary()->create(['file_path' => $path]);
        EventPoster::factory()->for($event)->create(['original_filename' => 'unselected.png']);
        $external = ExternalParticipant::factory()->create(['name' => 'Nama Saja', 'email' => null, 'phone' => null]);
        $registration = EventRegistration::factory()->external()->for($event)->invitedTo()->create(['external_participant_id' => $external->id]);

        $this->actingAs($this->staff('registrations.view'))
            ->get(route('events.participants.invitations.preview', [$event, $registration]))->assertOk()
            ->assertSee('Nama Saja')->assertSee('Agenda &lt;umum&gt;', false)->assertSee('Bawa undangan')
            ->assertSee('src="data:image/jpeg;base64,', false)->assertSee('src="data:image/png;base64,', false)
            ->assertDontSee('External Participant')->assertDontSee('Jenis Peserta')->assertDontSee('unselected.png');
        $pdf = $this->get($this->invitationUrl($event, $registration))->assertOk()->getContent();
        $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($pdf));
    }

    public function test_a_legacy_large_poster_derivative_is_resized_before_pdf_rendering(): void
    {
        $disk = Storage::fake('local');
        $event = Event::factory()->create();
        $poster = EventPoster::factory()->for($event)->primary()->create();
        $legacy = UploadedFile::fake()->image('legacy.jpg', 1600, 1000);
        $disk->put(EventPoster::variantPath($poster->file_path, 'invitation'), file_get_contents($legacy->getPathname()));
        $registration = EventRegistration::factory()->for($event)->invitedTo()->create();

        $pdf = $this->actingAs($this->staff('registrations.view'))->get($this->invitationUrl($event, $registration))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~/Subtype /Image\s+/Width 960\s+/Height 600\b~', $pdf);
        $this->assertDoesNotMatchRegularExpression('~/Subtype /Image\s+/Width 1600\s+/Height 1000\b~', $pdf);
        $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($pdf));
    }

    public function test_long_description_flows_across_pages_without_losing_the_qr(): void
    {
        $event = Event::factory()->create(['description' => str_repeat("Paragraf informasi yang panjang.\n", 100).str_repeat('X', 500)]);
        $registration = EventRegistration::factory()->for($event)->invitedTo()->create();
        $pdf = $this->actingAs($this->staff('registrations.view'))->get($this->invitationUrl($event, $registration))->assertOk()->getContent();

        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($pdf));
    }

    #[TestWith([25])]
    #[TestWith([50])]
    #[TestWith([100])]
    #[TestWith([300])]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_large_exports_process_ordered_pdf_batches_on_the_database_queue(int $count): void
    {
        Storage::fake('local');
        config(['event_invitations.direct_limit' => 50, 'event_invitations.batch_size' => 25]);
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
        $guests = EventRegistration::factory()->external()->for($event)->invitedTo($day)->count($count - $employees->count())->create();
        $this->actingAs($this->staff('registrations.manage'));
        $renderedIds = [];
        View::composer('events.partials.invitation-document', function (BladeView $view) use (&$renderedIds): void {
            foreach ($view->getData()['pages'] as $page) {
                $renderedIds[] = $page['registration']->id;
            }
        });

        $url = route('events.participants.invitations.export', $event);
        $data = $this->postJson($url, ['participant_type' => 'all'])->assertAccepted()
            ->assertJsonPath('status', 'pending')->assertJsonPath('participant_count', $count)->json();
        $this->assertSame([], $renderedIds);
        $this->getJson($url)->assertAccepted()->assertJsonPath('id', $data['id']);
        $this->assertDatabaseCount('jobs', 1);

        $this->workOneExportJob();
        $this->getJson($data['status_url'])->assertOk()->assertJsonPath('status', 'processing')->assertJsonPath('processed_count', 5);
        app()->call([new GenerateEventInvitationExport($data['id'], 0), 'handle']);
        $this->assertDatabaseCount('jobs', 1);
        $batches = intdiv($count, 5);
        for ($batch = 1; $batch <= $batches; $batch++) {
            $this->workOneExportJob();
        }
        $status = $this->getJson($data['status_url'])->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('processed_count', $count)->json();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([...$employees->modelKeys(), ...$guests->modelKeys()], $renderedIds);
        $export = EventInvitationExport::findOrFail($data['id']);
        $this->assertLessThan(128 * 1024 * 1024, $export->peak_memory_bytes);

        $download = $this->get($status['download_url'])->assertOk()->assertHeader('Content-Type', 'application/zip')
            ->assertDownload('forum-operasional-invitations.zip')->baseResponse;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($download->getFile()->getPathname(), ZipArchive::CHECKCONS));
        $this->assertSame($batches, $zip->numFiles);
        for ($index = 0; $index < $batches; $index++) {
            $this->assertSame(sprintf('undangan-%06d.pdf', $index + 1), $zip->getNameIndex($index));
            $this->assertInvitationPdf($zip->getFromIndex($index), 5);
        }
        foreach ([0 => $employees->first(), $batches - 1 => $guests->slice(-5)->first()] as $index => $registration) {
            $pdf = $zip->getFromIndex($index);
            $this->assertMatchesRegularExpression('~/Subtype /Image\s+/Width 256\s+/Height 192\b~', $pdf);
            $this->assertStringContainsString('/SMask ', $pdf);
            $this->assertSame('RA1:'.$registration->uuid, $this->invitationPayload($pdf));
        }
        $zip->close();
    }

    public function test_bulk_export_reports_a_failed_pdf_without_returning_a_partial_document(): void
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
            ->assertInternalServerError()->assertJsonValidationErrors(['export' => 'Ekspor undangan gagal dibuat. Silakan coba lagi.']);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $failure);
        $this->assertSame($previousLimit, ini_get('memory_limit'));
    }

    public function test_export_access_requires_owner_and_permission_and_cleanup_removes_expired_files(): void
    {
        Storage::fake('local');
        $owner = $this->staff('registrations.manage');
        $export = EventInvitationExport::factory()->create([
            'requested_by' => $owner->id, 'status' => EventInvitationExport::COMPLETED, 'expires_at' => now()->addHour(),
        ]);
        Storage::disk('local')->put($export->archivePath(), 'archive');
        $status = route('events.participants.invitations.export.status', [$export->event, $export]);
        $download = route('events.participants.invitations.export.download', [$export->event, $export]);

        $this->actingAs($this->staff('registrations.manage'))->get($download)->assertNotFound();
        $this->getJson($status)->assertNotFound();
        $this->actingAs($owner)->getJson($status)->assertOk()->assertJsonPath('download_url', $download);
        $this->get(route('events.participants.invitations.export.download', [Event::factory()->create(), $export]))->assertNotFound();
        $owner->revokePermissionTo('registrations.manage');
        $this->get($download)->assertForbidden();
        $owner->givePermissionTo('registrations.manage');

        $this->travel(2)->hours();
        $this->get($download)->assertNotFound();
        $this->artisan('app:prune-event-invitation-exports', ['--no-interaction' => true])->assertSuccessful();
        Storage::disk('local')->assertMissing($export->archivePath());
        $this->assertModelMissing($export);
    }

    public function test_worker_retries_and_reports_terminal_failure_then_owner_can_request_again(): void
    {
        Storage::fake('local');
        config(['event_invitations.direct_limit' => 0]);
        $event = Event::factory()->create();
        EventRegistration::factory()->for($event)->invitedTo()->create();
        $url = route('events.participants.invitations.export', $event);
        $this->actingAs($this->staff('registrations.manage'));
        $first = $this->postJson($url)->assertAccepted()->json();
        $this->mock(EventInvitationExportService::class, function ($mock): void {
            $mock->makePartial()->shouldReceive('render')->times(3)->andThrow(new RuntimeException('Renderer unavailable'));
        });
        Exceptions::fake();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->workOneExportJob();
            $this->getJson($first['status_url'])->assertOk()
                ->assertJsonPath('status', $attempt === 3 ? 'failed' : 'processing')->assertJsonPath('download_url', null);
            $this->travel(61)->seconds();
        }
        $this->assertDatabaseCount('jobs', 0);
        Storage::disk('local')->assertMissing(EventInvitationExport::findOrFail($first['id'])->archivePath());
        $second = $this->postJson($url)->assertAccepted()->assertJsonPath('status', 'pending')->json();
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function test_worker_retries_with_a_smaller_batch_and_preserves_completed_progress(): void
    {
        $disk = Storage::fake('local');
        $event = Event::factory()->create();
        $day = EventDay::factory()->for($event)->create();
        EventRegistration::factory()->external()->for($event)->invitedTo($day)->count(8)->create();
        EventRegistration::factory()->for($event)->invitedTo($day)->create();
        $this->actingAs($this->staff('registrations.manage'));
        $url = route('events.participants.invitations.export', $event);
        $request = $this->getJson($url.'?participant_type=external')->assertAccepted()->assertJsonPath('participant_count', 8)->json();
        $export = EventInvitationExport::findOrFail($request['id']);
        $export->update(['batch_size' => 25]);
        EventRegistration::factory()->external()->for($event)->invitedTo($day)->create();
        $this->workOneExportJob();
        $this->getJson($request['status_url'])->assertJsonPath('processed_count', 5);
        $firstPdf = $disk->get($export->batchPath(0));
        $this->assertInvitationPdf($firstPdf, 5);

        $pdfs = app(EventInvitationExportService::class);
        $this->mock(EventInvitationExportService::class, function ($mock) use ($pdfs): void {
            $mock->makePartial()->shouldReceive('render')->once()->withArgs(fn ($event, $rows): bool => $rows->count() === 3)
                ->andThrow(new RuntimeException('Simulated PDF render failure'));
            $mock->shouldReceive('render')->twice()->withArgs(fn ($event, $rows): bool => $rows->count() < 3)
                ->andReturnUsing($pdfs->render(...));
        });
        Exceptions::fake();
        $this->workOneExportJob();
        $this->getJson($request['status_url'])->assertJsonPath('processed_count', 5);
        $this->travel(16)->seconds();
        $this->workOneExportJob();
        $this->getJson($request['status_url'])->assertJsonPath('processed_count', 7);
        $this->workOneExportJob();
        $this->workOneExportJob();
        $status = $this->getJson($request['status_url'])->assertJsonPath('status', 'completed')->assertJsonPath('processed_count', 8)->json();
        $this->get($status['download_url'])->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertDatabaseCount('jobs', 0);
        $disk->assertDirectoryEmpty($export->directory().'/batches');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($disk->path($export->archivePath()), ZipArchive::CHECKCONS));
        $this->assertSame($firstPdf, $zip->getFromIndex(0));
        $this->assertInvitationPdf($zip->getFromIndex(1), 2);
        $this->assertInvitationPdf($zip->getFromIndex(2), 1);
        $zip->close();
    }

    public function test_a_lost_worker_reservation_is_retried_after_the_job_timeout_window(): void
    {
        Storage::fake('local');
        config(['event_invitations.direct_limit' => 0]);
        $event = Event::factory()->create();
        $day = EventDay::factory()->for($event)->create();
        EventRegistration::factory()->for($event)->invitedTo($day)->count(3)->create();
        $this->actingAs($this->staff('registrations.manage'));
        $request = $this->postJson(route('events.participants.invitations.export', $event))->assertAccepted()->json();
        $connection = app('queue')->connection(EventInvitationExportQueue::CONNECTION);
        $reserved = $connection->pop(EventInvitationExportQueue::QUEUE);
        $this->assertSame(120, $reserved->timeout());
        $this->assertSame(180, config('queue.connections.invitation_exports.retry_after'));

        $this->travel(121)->seconds();
        $this->workOneExportJob();
        $this->getJson($request['status_url'])->assertJsonPath('status', 'pending')->assertJsonPath('processed_count', 0);
        $this->travel(60)->seconds();
        $this->workOneExportJob();
        $this->getJson($request['status_url'])->assertJsonPath('status', 'processing')->assertJsonPath('processed_count', 2);
        $this->workOneExportJob();
        $this->workOneExportJob();
        $this->getJson($request['status_url'])->assertJsonPath('status', 'completed')->assertJsonPath('processed_count', 3);
        $this->assertDatabaseCount('jobs', 0);
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

    private function workOneExportJob(): void
    {
        app('queue.worker')->runNextJob(EventInvitationExportQueue::CONNECTION, EventInvitationExportQueue::QUEUE, new WorkerOptions(sleep: 0, maxTries: 3));
    }

    private function invitationPayload(string $pdf, int $index = 0): string
    {
        // The opaque QR is embedded as an RGB stream with PNG row predictors.
        $this->assertGreaterThan($index, preg_match_all('~/Subtype /Image\s+/Width (256)\s+/Height (256)(?:(?!endobj).)*?/ColorSpace /DeviceRGB\s+/BitsPerComponent 8\s+/Length (\d+)\s*>>\s*stream\r?\n~s', $pdf, $images, PREG_SET_ORDER | PREG_OFFSET_CAPTURE));
        $image = $images[$index];
        $streamOffset = $image[0][1] + strlen($image[0][0]);
        $imageData = substr($pdf, $streamOffset, (int) $image[3][0]);
        $png = "\x89PNG\r\n\x1a\n".$this->pngChunk('IHDR', pack('NNCCCCC', (int) $image[1][0], (int) $image[2][0], 8, 2, 0, 0, 0))
            .$this->pngChunk('IDAT', $imageData).$this->pngChunk('IEND', '');

        return (new QrReader($png, QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]);
    }

    private function assertInvitationPdf(string $pdf, int $pages = 1): void
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0\S*\s+0\S*\s+419\.\d+\s+595\.\d+\s*\]/', $pdf);
        $this->assertSame($pages, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
