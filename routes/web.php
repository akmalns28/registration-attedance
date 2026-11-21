<?php

use App\Http\Controllers\AnalyticController;
use App\Http\Controllers\AttendanceCheckinController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\AttendanceReportExportController;
use App\Http\Controllers\AttendanceScannerController;
use App\Http\Controllers\Auth\KeycloakController;
use App\Http\Controllers\BulkImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventDayController;
use App\Http\Controllers\EventDayInvitationController;
use App\Http\Controllers\EventInvitationExportController;
use App\Http\Controllers\EventNotificationController;
use App\Http\Controllers\EventParticipantController;
use App\Http\Controllers\EventRegistrationController;
use App\Http\Controllers\EventRegistrationQrController;
use App\Http\Controllers\EventScheduleController;
use App\Http\Controllers\ExternalParticipantController;
use App\Http\Controllers\ManualAttendanceController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\UserController;
use App\Services\BulkImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/registrations/{registration}', EventRegistrationController::class)
    ->whereUuid('registration')
    ->name('registrations.show');

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
|
| Routes that can only be accessed by unauthenticated users.
|
*/

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->middleware('cache.headers:private;no_store')->name('login');

    Route::get('/auth/keycloak/redirect', [KeycloakController::class, 'redirect'])->name('auth.keycloak.redirect');

    Route::get('/auth/keycloak/callback', [KeycloakController::class, 'callback'])->name('auth.keycloak.callback');
});

Route::post('/logout', [KeycloakController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
|
| All routes require authentication and an active user account.
|
*/

Route::middleware(['auth', 'active'])->group(function () {
    foreach (BulkImportService::MODULES as $module => $definition) {
        $prefix = $module === 'users' ? 'settings/users' : $module;
        Route::prefix($prefix.'/import')->name($definition['route'].'.import.')
            ->middleware('can:'.$definition['permission'])->group(function () use ($module) {
                Route::get('/', [BulkImportController::class, 'index'])->defaults('module', $module)->name('index');
                Route::get('/template', [BulkImportController::class, 'template'])->defaults('module', $module)->name('template');
                Route::post('/preview', [BulkImportController::class, 'preview'])->defaults('module', $module)->name('preview');
                Route::get('/{import}', [BulkImportController::class, 'show'])->whereUuid('import')->defaults('module', $module)->name('show');
                Route::post('/{import}/confirm', [BulkImportController::class, 'confirm'])->whereUuid('import')->defaults('module', $module)->name('confirm');
                Route::get('/{import}/result', [BulkImportController::class, 'result'])->whereUuid('import')->defaults('module', $module)->name('result');
                Route::get('/{import}/errors', [BulkImportController::class, 'errors'])->whereUuid('import')->defaults('module', $module)->name('errors');
            });
    }
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('/', fn (): RedirectResponse => redirect()->route('dashboard'));

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('can:dashboard.view')
        ->name('dashboard');

    Route::middleware('can:reports.view')->group(function () {
        Route::get('/analytic', [AnalyticController::class, 'index'])->name('analytic.index');
        Route::get('/analytic/data', [AnalyticController::class, 'data'])->name('analytic.data');
    });

    Route::get('/notifications', [EventNotificationController::class, 'index'])
        ->middleware('can:events.view')->name('notifications.index');
    Route::post('/notifications/{notification}/retry', [EventNotificationController::class, 'retry'])
        ->middleware('can:events.update')->name('notifications.retry');
    Route::post('/notifications/{notification}/resend', [EventNotificationController::class, 'resend'])
        ->middleware('can:events.update')->name('notifications.resend');
    Route::post('/events/{event}/notifications/invitations', [EventNotificationController::class, 'invitations'])
        ->middleware('can:events.update')->name('events.notifications.invitations');

    /*
    |--------------------------------------------------------------------------
    | Settings Management
    |--------------------------------------------------------------------------
    */

    Route::prefix('settings')
        ->name('settings.')
        ->group(function () {
            /*
            | Role Management
            */

            Route::resource('roles', RoleController::class)
                ->except(['show'])
                ->middleware('can:roles.manage');

            /*
            | User Management
            */

            Route::resource('users', UserController::class)
                ->except(['show', 'destroy'])
                ->middleware('can:users.manage');
        });

    /*
    |--------------------------------------------------------------------------
    | Master Data - Department Management
    |--------------------------------------------------------------------------
    */

    Route::resource('departments', DepartmentController::class)
        ->except(['show'])
        ->middlewareFor('index', 'can:departments.view')
        ->middlewareFor('create', 'can:departments.create')
        ->middlewareFor('store', 'can:departments.create')
        ->middlewareFor('edit', 'can:departments.update')
        ->middlewareFor('update', 'can:departments.update')
        ->middlewareFor('destroy', 'can:departments.delete');

    /*
    |--------------------------------------------------------------------------
    | Master Data - Employee Management
    |--------------------------------------------------------------------------
    */

    Route::resource('employees', EmployeeController::class)
        ->except(['show'])
        ->middlewareFor('index', 'can:employees.view')
        ->middlewareFor('create', 'can:employees.manage')
        ->middlewareFor('store', 'can:employees.manage')
        ->middlewareFor('edit', 'can:employees.manage')
        ->middlewareFor('update', 'can:employees.manage')
        ->middlewareFor('destroy', 'can:employees.manage');

    /*
    |--------------------------------------------------------------------------
    | Master Data - External Participant Management
    |--------------------------------------------------------------------------
    */

    Route::resource('external-participants', ExternalParticipantController::class)
        ->except(['show'])
        ->middlewareFor('index', 'can:external-participants.view')
        ->middlewareFor('create', 'can:external-participants.manage')
        ->middlewareFor('store', 'can:external-participants.manage')
        ->middlewareFor('edit', 'can:external-participants.manage')
        ->middlewareFor('update', 'can:external-participants.manage')
        ->middlewareFor('destroy', 'can:external-participants.manage');

    /*
    |--------------------------------------------------------------------------
    | Event Management
    |--------------------------------------------------------------------------
    |
    | Main event CRUD.
    | Event route model binding uses UUID.
    |
    */

    Route::resource('events', EventController::class)->middlewareFor('index', 'can:events.view')->middlewareFor('show', 'can:events.view')->middlewareFor('create', 'can:events.create')->middlewareFor('store', 'can:events.create')->middlewareFor('edit', 'can:events.update')->middlewareFor('update', 'can:events.update')->middlewareFor('destroy', 'can:events.delete');

    Route::get('events/{event}/logo', [EventController::class, 'logo'])->whereUuid('event')->name('events.logo');

    /*
    |--------------------------------------------------------------------------
    | Event Day & Rundown Management
    |--------------------------------------------------------------------------
    |
    | Scoped implicit model binding ensures:
    |
    | 1. Event Day belongs to the requested Event.
    | 2. Event Schedule belongs to the requested Event Day.
    |
    | Structure:
    |
    | Event
    |   └── Event Day
    |         └── Event Schedule
    |
    */

    Route::scopeBindings()->group(function () {
        Route::get('events/{event}/reports/data', AttendanceReportController::class)
            ->middleware(['can:events.view', 'can:reports.view'])->name('events.reports.data');

        Route::middleware(['can:events.view', 'can:reports.view', 'can:reports.export'])->group(function () {
            Route::get('events/{event}/reports/export/excel', [AttendanceReportExportController::class, 'excel'])->name('events.reports.export.excel');
            Route::get('events/{event}/reports/export/csv', [AttendanceReportExportController::class, 'csv'])->name('events.reports.export.csv');
            Route::get('events/{event}/reports/export/pdf', [AttendanceReportExportController::class, 'pdf'])->name('events.reports.export.pdf');
            Route::get('events/{event}/reports/print', [AttendanceReportExportController::class, 'print'])->name('events.reports.print');
        });

        Route::get('events/{event}/participants/{registration}/qr', EventRegistrationQrController::class)
            ->middleware('can:events.view')->name('events.participants.qr');

        Route::get('events/{event}/participants/invitations/export', [EventInvitationExportController::class, 'bulk'])
            ->whereUuid('event')->middleware(['can:events.view', 'can:registrations.manage'])->name('events.participants.invitations.export');

        Route::get('events/{event}/participants/{registration}/invitation', [EventInvitationExportController::class, 'download'])
            ->whereUuid(['event', 'registration'])->middleware(['can:events.view', 'can:registrations.view'])->name('events.participants.invitation');

        Route::prefix('events/{event}/participants/{registration}/invitations')->name('events.participants.invitations.')
            ->middleware('can:events.view')->group(function () {
                Route::get('preview', [EventDayInvitationController::class, 'preview'])
                    ->middleware('can:registrations.view')->name('preview');
                Route::middleware('can:events.update')->group(function () {
                    Route::get('/', [EventDayInvitationController::class, 'edit'])->name('edit');
                    Route::put('/', [EventDayInvitationController::class, 'update'])->name('update');
                    Route::post('send', [EventDayInvitationController::class, 'send'])->name('send');
                });
            });

        /*
        |--------------------------------------------------------------------------
        | Event Day Management
        |--------------------------------------------------------------------------
        */

        Route::get('events/{event}/days/{day}', [EventDayController::class, 'show'])
            ->whereNumber('day')->middleware('can:events.view')->name('events.days.show');

        Route::get('events/{event}/days/{day}/attendance', [EventDayController::class, 'attendance'])
            ->whereNumber('day')->middleware(['can:events.view', 'can:attendance.view'])->name('events.days.attendance');

        Route::prefix('events/{event}/days/{day}/attendance/manual')->name('events.days.manual.')
            ->where(['event' => '[0-9a-fA-F-]{36}', 'day' => '[0-9]+'])
            ->middleware(['can:events.view', 'can:attendance.scan', 'throttle:60,1'])->group(function () {
                Route::get('participants', [ManualAttendanceController::class, 'search'])->name('search');
                Route::post('check-in', [ManualAttendanceController::class, 'checkIn'])->name('check-in');
            });

        Route::resource('events.days', EventDayController::class)
            ->parameters([
                'days' => 'day',
            ])
            ->except(['index', 'show'])
            ->middleware('can:events.update');

        /*
        |--------------------------------------------------------------------------
        | Event Schedule / Rundown Management
        |--------------------------------------------------------------------------
        */

        Route::resource('events.days.schedules', EventScheduleController::class)
            ->parameters([
                'days' => 'day',
                'schedules' => 'schedule',
            ])
            ->except(['index', 'show'])
            ->middleware('can:events.update');

        /*
    | Register & Cancel Participants
    */

        Route::resource('events.participants', EventParticipantController::class)
            ->parameters([
                'participants' => 'registration',
            ])
            ->only(['store', 'destroy'])
            ->middleware('can:events.update');

        Route::get('events/{event}/participants/candidates', [EventParticipantController::class, 'candidates'])
            ->whereUuid('event')->middleware(['can:events.view', 'can:events.update'])->name('events.participants.candidates');

        /*
    | Reactivate Registration
    */

        Route::post('events/{event}/participants/{registration}/reactivate', [EventParticipantController::class, 'reactivate'])
            ->middleware('can:events.update')
            ->name('events.participants.reactivate');
    });

    /*
    |--------------------------------------------------------------------------
    | Attendance - QR Scanner
    |--------------------------------------------------------------------------
    */

    Route::get('/attendance/scanner', [AttendanceScannerController::class, 'index'])
        ->middleware('can:attendance.scan')
        ->name('attendance.scanner');

    Route::get('/events/{event}/scanner', [AttendanceScannerController::class, 'forEvent'])
        ->whereUuid('event')
        ->middleware('can:attendance.scan')
        ->name('events.scanner');

    Route::post('/attendance/scanner/validate', [AttendanceScannerController::class, 'validate'])
        ->middleware(['can:attendance.scan', 'throttle:60,1'])
        ->name('attendance.scanner.validate');

    Route::post('/attendance/check-in', AttendanceCheckinController::class)
        ->middleware(['can:attendance.scan', 'throttle:60,1'])->name('attendance.check-in');

    Route::middleware(['can:attendance.scan', 'throttle:60,1'])->prefix('attendance/manual')->name('attendance.manual.')->group(function () {
        Route::get('/participants', [ManualAttendanceController::class, 'search'])->name('search');
        Route::post('/check-in', [ManualAttendanceController::class, 'checkIn'])->name('check-in');
    });
});

/*
|--------------------------------------------------------------------------
| Development Routes
|--------------------------------------------------------------------------
|
| Only accessible when APP_ENV=local.
|
*/

if (app()->environment('local')) {
    Route::view('/components', 'components-preview.index')->name('components.preview');
}
