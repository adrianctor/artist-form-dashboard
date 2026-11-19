<?php

use App\Http\Controllers\ArtistProfileController;
use App\Http\Controllers\ArtistSearchController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Default route: ALWAYS login when unauthenticated (user requirement).
| Authenticated users hitting / land on the dashboard.
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Spec S6: My profile — artist info create/edit (also the gate's
    // redirect target; EnsureArtistProfileComplete lets this route through).
    Route::get('my-profile', [ArtistProfileController::class, 'show'])->name('my-profile');
    Route::patch('my-profile', [ArtistProfileController::class, 'update'])->name('my-profile.update');

    // Spec S4: artist search for the release form's autofill.
    Route::get('artist-search', [ArtistSearchController::class, 'index'])->name('artist-search');
});

require __DIR__.'/calendar.php';
require __DIR__.'/social.php';
require __DIR__.'/songs.php';
require __DIR__.'/users.php';
require __DIR__.'/settings.php';
