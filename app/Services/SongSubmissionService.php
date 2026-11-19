<?php

namespace App\Services;

use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared song-submission pipeline used by the API (SongController) and the
 * in-app release form (ReleaseFormController). Keeps ownership matching and
 * append-version semantics in exactly one place (spec S1).
 */
class SongSubmissionService
{
    /**
     * @param  array<string, mixed>  $validated  payload WITHOUT song_code
     * @param  string|null  $songCode  append to this song when present
     * @param  int|null  $createdBy  authenticated user that hit submit (spec S6)
     * @return array{song: Song, submission: \App\Models\SongSubmission}
     *
     * @throws ValidationException when song_code does not match a song
     */
    public function submit(array $validated, ?string $songCode = null, ?int $createdBy = null): array
    {
        // Ownership: match any artist email from the form against dashboard
        // users (users only see the songs they registered).
        $artistEmails = array_map(
            'strtolower',
            array_filter(array_column($validated['artists'] ?? [], 'email'))
        );
        $ownerId = $artistEmails
            ? User::whereIn('email', $artistEmails)->value('id')
            : null;

        return DB::transaction(function () use ($validated, $songCode, $ownerId, $artistEmails, $createdBy) {
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
                    'user_id' => $ownerId,
                    'track_title' => $validated['track_title'],
                    'submission_count' => 0,
                ]);
            }

            $submission = $song->submissions()->create([
                'payload' => $validated,
                'track_title' => $validated['track_title'],
                // Spec S6: the history must say who created this submit.
                'created_by' => $createdBy,
            ]);

            // Spec S6: denormalized ",email," list so EVERY artist with a
            // dashboard account can see this song (not only the first match).
            $merged = $this->mergeArtistEmails($song->artist_emails, $artistEmails);

            $song->update([
                'track_title' => $validated['track_title'],
                'submission_count' => $song->submission_count + 1,
                // Backfill ownership for songs submitted before the owner had an account.
                'user_id' => $song->user_id ?? $ownerId,
                'artist_emails' => $merged,
            ]);

            return ['song' => $song->fresh(), 'submission' => $submission];
        });
    }

    /**
     * Union of the existing ",email," list with the new submit's emails.
     *
     * @param  array<int, string>  $newEmails
     */
    private function mergeArtistEmails(?string $existing, array $newEmails): string
    {
        $all = [];

        foreach (explode(',', (string) $existing) as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $all[$email] = true;
            }
        }
        foreach ($newEmails as $email) {
            if ($email !== '') {
                $all[$email] = true;
            }
        }

        return ','.implode(',', array_keys($all)).',';
    }
}
