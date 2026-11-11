<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\KeycloakController;

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

Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });

    Route::view('/dashboard', 'dashboard.index')->name('dashboard');

    Route::post('/logout', [KeycloakController::class, 'logout'])->name('logout');
});

/*
|--------------------------------------------------------------------------
| Development Routes
|--------------------------------------------------------------------------
*/

if (app()->environment('local')) {
    Route::view('/components', 'components-preview.index')->name('components.preview');
}
