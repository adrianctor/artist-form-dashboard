<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SongController;

/*
|--------------------------------------------------------------------------
| API Routes — consumed by the Elementor/WordPress form
|--------------------------------------------------------------------------
|
| Auth: Sanctum bearer token (personal access token created per deploy).
| The form sends: Authorization: Bearer {token}
|
*/

Route::middleware('auth:sanctum')->group(function () {
    // POST: create submission (new song_code or append version to existing)
    Route::post('/v1/songs', [SongController::class, 'store']);

    // GET: latest payload for form prefill
    Route::get('/v1/songs/{song_code}', [SongController::class, 'show']);

    // GET: full version history (oldest first)
    Route::get('/v1/songs/{song_code}/history', [SongController::class, 'history']);
});
