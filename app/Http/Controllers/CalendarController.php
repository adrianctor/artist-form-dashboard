<?php

namespace App\Http\Controllers;

use App\Models\Song;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin calendar: every song grouped by the date it was registered.
 * Non-admins get 403 (spec L10: only the admin sees all songs).
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $songsByDate = Song::query()
            ->select(['id', 'song_code', 'track_title', 'submission_count', 'created_at'])
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Song $song) => $song->created_at->toDateString())
            ->map(fn ($songs) => $songs->map(fn (Song $song) => [
                'song_code' => $song->song_code,
                'track_title' => $song->track_title,
                'submission_count' => $song->submission_count,
                'created_at' => $song->created_at->toIso8601String(),
            ])->values()->all())
            ->all();

        return Inertia::render('Calendar', [
            'songsByDate' => $songsByDate,
        ]);
    }
}
