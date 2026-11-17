<?php

use App\Http\Controllers\AttendanceCheckinController;
use App\Http\Controllers\AttendanceScannerController;
use App\Http\Controllers\Auth\KeycloakController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventDayController;
use App\Http\Controllers\EventNotificationController;
use App\Http\Controllers\EventParticipantController;
use App\Http\Controllers\EventRegistrationController;
use App\Http\Controllers\EventRegistrationQrController;
use App\Http\Controllers\EventScheduleController;
use App\Http\Controllers\ExternalParticipantController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\UserController;
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
    Route::view('/login', 'auth.login')->name('login');

    Route::get('/auth/keycloak/redirect', [KeycloakController::class, 'redirect'])->name('auth.keycloak.redirect');

    Route::get('/auth/keycloak/callback', [KeycloakController::class, 'callback'])->name('auth.keycloak.callback');
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
|
| All routes require authentication and an active user account.
|
*/

Route::middleware(['auth', 'active'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('/', fn (): RedirectResponse => redirect()->route('dashboard'));

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('can:dashboard.view')
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', [KeycloakController::class, 'logout'])->name('logout');

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
        Route::get('events/{event}/participants/{registration}/qr', EventRegistrationQrController::class)
            ->middleware('can:events.view')->name('events.participants.qr');

        /*
        |--------------------------------------------------------------------------
        | Event Day Management
        |--------------------------------------------------------------------------
        */

        Route::get('events/{event}/days/{day}', [EventDayController::class, 'show'])
            ->whereNumber('day')->middleware('can:events.view')->name('events.days.show');

        Route::get('events/{event}/days/{day}/attendance', [EventDayController::class, 'attendance'])
            ->whereNumber('day')->middleware(['can:events.view', 'can:attendance.view'])->name('events.days.attendance');

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
