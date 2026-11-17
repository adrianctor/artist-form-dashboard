<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Models\SongSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SongController extends Controller
{
    /**
     * Persist a form submission.
     *
     * - No song_code (or empty): creates a new song and generates SONG-XXXXXXXX.
     * - Existing song_code: appends a new version to the history.
     * - Unknown song_code: 422 (never silently create from a stale code).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'song_code' => ['nullable', 'string', 'max:32', 'regex:/^SONG-[A-Z0-9]{8}$/'],
            'track_title' => ['required', 'string', 'max:500'],
            'cover_artwork_creative_direction' => ['nullable', 'string'],
            'audio_link' => ['nullable', 'string', 'max:2000'],
            'genre' => ['nullable', 'string', 'max:255'],
            'country_recording' => ['nullable', 'string', 'max:255'],
            'audio_style' => ['nullable', 'string', 'max:50'],
            'writers' => ['nullable', 'string'],
            'explicit_content' => ['nullable', 'string', 'max:10'],
            'is_cover' => ['nullable', 'string', 'max:10'],
            'original_song_link' => ['nullable', 'string', 'max:2000'],
            'original_track_credits' => ['nullable', 'string'],
            'artist_count' => ['nullable', 'integer', 'min:0', 'max:4'],
            'artists' => ['nullable', 'array'],
            'artists.*.name' => ['nullable', 'string', 'max:255'],
            'artists.*.real_name' => ['nullable', 'string', 'max:255'],
            'artists.*.email' => ['nullable', 'email', 'max:255'],
            'artists.*.role' => ['nullable', 'string', 'max:255'],
            'artists.*.country' => ['nullable', 'string', 'max:255'],
            'artists.*.city' => ['nullable', 'string', 'max:255'],
            'artists.*.estate' => ['nullable', 'string', 'max:255'],
            'artists.*.photo' => ['nullable', 'string', 'max:2000'],
            'artists.*.bio' => ['nullable', 'string'],
            'artists.*.status' => ['nullable', 'string', 'max:50'],
            'artists.*.links' => ['nullable', 'array'],
        ]);

        $songCode = $validated['song_code'] ?? null;
        unset($validated['song_code']);

        $result = DB::transaction(function () use ($validated, $songCode) {
            if ($songCode) {
                $song = Song::where('song_code', $songCode)->first();

                if (! $song) {
                    throw ValidationException::withMessages([
                        'song_code' => 'No song found with this code.',
                    ]);
                }
            } else {
                $song = Song::create([
                    'song_code' => Song::generateSongCode(),
                    'track_title' => $validated['track_title'],
                    'submission_count' => 0,
                ]);
            }

            $submission = $song->submissions()->create([
                'payload' => $validated,
                'track_title' => $validated['track_title'],
            ]);

            $song->update([
                'track_title' => $validated['track_title'],
                'submission_count' => $song->submission_count + 1,
            ]);

            return ['song' => $song->fresh(), 'submission' => $submission];
        });

        return response()->json([
            'song_code' => $result['song']->song_code,
            'submission_id' => $result['submission']->id,
            'version' => $result['song']->submission_count,
            'track_title' => $result['song']->track_title,
        ], 201);
    }

    /**
     * Latest submission payload — used by the form to prefill fields.
     */
    public function show(string $songCode): JsonResponse
    {
        $song = Song::where('song_code', $songCode)
            ->with(['submissions' => fn ($q) => $q->latest('id')->limit(1)])
            ->firstOrFail();

        $latest = $song->submissions->first();

        return response()->json([
            'song_code' => $song->song_code,
            'track_title' => $song->track_title,
            'version' => $song->submission_count,
            'updated_at' => $song->updated_at?->toIso8601String(),
            'payload' => $latest?->payload ?? [],
        ]);
    }

    /**
     * Full version history, oldest first.
     */
    public function history(string $songCode): JsonResponse
    {
        $song = Song::where('song_code', $songCode)->firstOrFail();

        $submissions = $song->submissions()
            ->orderBy('id')
            ->get()
            ->map(fn (SongSubmission $s) => [
                'id' => $s->id,
                'version' => null, // filled below by position
                'track_title' => $s->track_title,
                'payload' => $s->payload,
                'created_at' => $s->created_at?->toIso8601String(),
            ])
            ->values()
            ->each(function ($item, $index) {
                $item['version'] = $index + 1;
            });

        return response()->json([
            'song_code' => $song->song_code,
            'track_title' => $song->track_title,
            'total_versions' => $song->submission_count,
            'data' => $submissions,
        ]);
    }
}
