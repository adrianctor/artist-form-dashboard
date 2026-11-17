<?php

use App\Http\Controllers\SocialAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Social login (spec S4) — Google, Microsoft, Apple
|--------------------------------------------------------------------------
| Buttons on the login page only render when the matching env keys exist.
*/

Route::get('/auth/{provider}', [SocialAuthController::class, 'redirect'])
    ->whereIn('provider', ['google', 'microsoft', 'apple'])
    ->name('auth.provider');

Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['google', 'microsoft', 'apple'])
    ->name('auth.provider.callback');
