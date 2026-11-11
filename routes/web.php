<?php


use App\Http\Controllers\Auth\KeycloakController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
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
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', fn(): RedirectResponse => redirect()->route('dashboard'));

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('can:dashboard.view')
        ->name('dashboard');
    Route::post('/logout', [KeycloakController::class, 'logout'])->name('logout');

    Route::prefix('settings')
        ->name('settings.')
        ->group(function () {
            Route::resource('roles', RoleController::class)
                ->except(['show'])
                ->middleware('can:roles.manage');

            Route::resource('users', UserController::class)
                ->except(['show', 'destroy'])
                ->middleware('can:users.manage');
        });
    Route::resource('departments', DepartmentController::class)
        ->except(['show'])
        ->middlewareFor('index', 'can:departments.view')
        ->middlewareFor('create', 'can:departments.create')
        ->middlewareFor('store', 'can:departments.create')
        ->middlewareFor('edit', 'can:departments.update')
        ->middlewareFor('update', 'can:departments.update')
        ->middlewareFor('destroy', 'can:departments.delete');

    Route::resource('employees', EmployeeController::class)
        ->except(['show'])
        ->middlewareFor('index', 'can:employees.view')
        ->middlewareFor('create', 'can:employees.manage')
        ->middlewareFor('store', 'can:employees.manage')
        ->middlewareFor('edit', 'can:employees.manage')
        ->middlewareFor('update', 'can:employees.manage')
        ->middlewareFor('destroy', 'can:employees.manage');

    Route::view('/events', 'placeholder', [
        'title' => 'Event Management',
        'description' => 'Manajemen event akan tersedia di halaman ini.',
    ])
        ->middleware('can:events.view')
        ->name('events.index');

    Route::view('/events/create', 'placeholder', [
        'title' => 'Create Event',
        'description' => 'Pembuatan event akan tersedia di halaman ini.',
    ])
        ->middleware('can:events.create')
        ->name('events.create');

    Route::view('/attendance/scanner', 'placeholder', [
        'title' => 'QR Scanner',
        'description' => 'Pemindaian QR untuk absensi akan tersedia di halaman ini.',
    ])
        ->middleware('can:attendance.scan')
        ->name('attendance.scanner');
});

/*
|--------------------------------------------------------------------------
| Development Routes
|--------------------------------------------------------------------------
*/

if (app()->environment('local')) {
    Route::view('/components', 'components-preview.index')->name('components.preview');
}
