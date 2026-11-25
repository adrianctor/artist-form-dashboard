<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Models\SongSubmission;
use App\Services\SongSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SongController extends Controller
{
    public function __construct(private SongSubmissionService $submissions) {}

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
            // Spec S3 (profile-writers-publishing): the in-app form sends
            // rows; legacy consumers still send a string — accept both.
            'writers' => ['nullable', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('The writers field must be a string or an array.');
                }
            }],
            'explicit_content' => ['nullable', 'string', 'max:10'],
            'publishing' => ['nullable', 'string', 'in:afrodise,none'],
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
            // Spec S5: per-artist publishing rides the same payload.
            'artists.*.publishing' => ['nullable', 'string', 'in:AFRODISE,COPYRIGHT CONTROL,OTHER'],
            'artists.*.publishing_custom' => ['nullable', 'string', 'max:255'],
            'artists.*.ipi_number' => ['nullable', 'string', 'max:50'],
            'artists.*.links' => ['nullable', 'array'],
        ]);

        $songCode = $validated['song_code'] ?? null;
        unset($validated['song_code']);

        $result = $this->submissions->submit($validated, $songCode, $request->user()?->id);

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
