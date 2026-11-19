<?php

namespace App\Http\Controllers;

use App\Models\Song;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar (spec S2/S3): every form submit grouped by the date it was
 * made. Accessible to all authenticated users; each user sees their own
 * songs (admins see everything) via scopeVisibleTo.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $songsByDate = Song::query()
            ->visibleTo($request->user())
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
