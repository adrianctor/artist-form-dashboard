<?php

use App\Http\Controllers\ReleaseFormController;
use App\Http\Controllers\SongPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Song history pages (dashboard side)
|--------------------------------------------------------------------------
| Owner: songs-pages work unit. Parent owns routes/web.php.
*/

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function () {
    Route::get('/songs', [SongPageController::class, 'index'])->name('songs.index');
    Route::get('/songs/{songCode}', [SongPageController::class, 'show'])->name('songs.show');

    // Spec S11: edit/delete a submit — only the user who sent it.
    Route::get('/submissions/{submission}/edit', [ReleaseFormController::class, 'edit'])->name('submissions.edit');
    Route::patch('/submissions/{submission}', [ReleaseFormController::class, 'update'])->name('submissions.update');
    Route::delete('/submissions/{submission}', [ReleaseFormController::class, 'destroy'])->name('submissions.destroy');
});
