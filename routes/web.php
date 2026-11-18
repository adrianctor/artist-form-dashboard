<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------
| Default route: ALWAYS login when unauthenticated (user requirement).
| Authenticated users hitting / land on the dashboard.
|------------------------------------------------------------------------
*/
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/social.php';
require __DIR__.'/songs.php';
require __DIR__.'/users.php';
require __DIR__.'/settings.php';
