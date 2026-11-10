<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::view('/dashboard', 'dashboard.index')->name('dashboard');

Route::get('/components', function () {
    abort_unless(app()->environment('local'), 404);

    return view('components-preview.index');
})->name('components.preview');