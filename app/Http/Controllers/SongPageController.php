<?php

namespace App\Http\Controllers;

use App\Models\Song;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard pages for song history.
 * Implemented by the songs-pages work unit.
 */
class SongPageController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('songs/Index', [
            'songs' => Song::query()
                ->visibleTo($request->user())
                ->orderByDesc('updated_at')
                ->paginate(15)
                ->withQueryString()
                ->through(fn (Song $song) => [
                    'id' => $song->id,
                    'song_code' => $song->song_code,
                    'track_title' => $song->track_title,
                    'submission_count' => $song->submission_count,
                    'updated_at' => $song->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    public function show(\Illuminate\Http\Request $request, string $songCode): Response
    {
        // Visibility via the same scope as the index (spec S6): owner,
        // creator of any submit, or any artist email — admins see all.
        $song = Song::visibleTo($request->user())
            ->where('song_code', $songCode)
            ->with(['submissions' => fn ($q) => $q->orderByDesc('id')->with('createdBy')])
            ->firstOrFail();

        return Inertia::render('songs/Show', [
            'song' => [
                'id' => $song->id,
                'song_code' => $song->song_code,
                'track_title' => $song->track_title,
                'submission_count' => $song->submission_count,
                'updated_at' => $song->updated_at?->toIso8601String(),
            ],
            'submissions' => $song->submissions->values()->map(fn ($s, $i) => [
                'id' => $s->id,
                'version' => $song->submission_count - $i,
                'track_title' => $s->track_title,
                'payload' => $s->payload,
                'created_at' => $s->created_at?->toIso8601String(),
                // Spec S6: the history must say who created this submit.
                'created_by' => $s->createdBy?->name ?? $s->createdBy?->email,
            ]),
        ]);
    }
}
