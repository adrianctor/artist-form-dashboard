<?php

use App\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin-only calendar: all songs registered by date (spec L10).
|--------------------------------------------------------------------------
| Owner: calendar unit. Parent owns routes/web.php.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
});
