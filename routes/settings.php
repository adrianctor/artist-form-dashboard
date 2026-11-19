<?php

use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\ConfirmPasswordWhenChosen;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'profile.complete'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Spec S3: only users who already chose a password re-confirm it —
    // social signups (password_set_at NULL) go straight to the first-time
    // setup flow.
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(ConfirmPasswordWhenChosen::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Spec S2: email a signed first-time-setup link (60 min, one shot).
    Route::post('settings/security/setup-password-link', [PasswordSetupController::class, 'sendLink'])
        ->middleware('throttle:6,1')
        ->name('password.setup.link');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

