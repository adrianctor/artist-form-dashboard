<?php

use App\Http\Controllers\PasswordSetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| First-time password setup (spec S1/S2)
|--------------------------------------------------------------------------
| The link arrives by email with a temporary signature (60 min). These
| routes are public on purpose — the signature is the authentication, and
| the store action refuses (410) once a password already exists.
*/

Route::get('/set-password', [PasswordSetupController::class, 'show'])->name('password.setup');
Route::post('/set-password', [PasswordSetupController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('password.setup.store');
