<?php

use App\Http\Controllers\SongPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Song history pages (dashboard side)
|--------------------------------------------------------------------------
| Owner: songs-pages work unit. Parent owns routes/web.php.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/songs', [SongPageController::class, 'index'])->name('songs.index');
    Route::get('/songs/{songCode}', [SongPageController::class, 'show'])->name('songs.show');
});
