<?php

namespace App\Http\Controllers;

use App\Mail\ReleaseFormSubmitted;
use App\Models\Song;
use App\Models\SongSubmission;
use App\Services\SongSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app Music Release Form (spec S1) — replaces the WordPress/Elementor
 * form. Submission reuses the shared song pipeline (ownership by artist
 * email) and carries `publishing` (spec S3).
 */
class ReleaseFormController extends Controller
{
    public function __construct(private SongSubmissionService $submissions)
    {
    }

    public function show(Request $request): Response
    {
        return Inertia::render('ReleaseForm', [
            'authUser' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    /**
     * Spec S1+S3: same pipeline as POST /api/v1/songs, session-authenticated,
     * with the Publishing radio and the form's required-field rules.
     * Success lands on the calendar with the new track (spec S5).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedRelease($request);

        $this->submissions->submit($validated, null, $request->user()?->id);

        // Spec S2: system email replaces EmailJS (same recipients). The
        // mailable's envelope carries to/cc/replyTo.
        Mail::send(new ReleaseFormSubmitted($validated));

        // Spec S5: after a successful submit the user lands on the calendar
        // (with the new track on today's date) + a visible toast.
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Release submitted successfully.',
        ]);

        return to_route('calendar');
    }

    /**
     * Spec S11: edit page — reuses ReleaseForm with an editSubmission prop
     * (prefilled). Only the user who sent the submit may open it.
     */
    public function edit(Request $request, int $submission): Response
    {
        $record = $this->findEditableSubmission($request, $submission);

        return Inertia::render('ReleaseForm', [
            'authUser' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'editSubmission' => [
                'id' => $record->id,
                'payload' => $record->payload,
            ],
        ]);
    }

    /**
     * Spec S11: update a submit in place — never creates a new version.
     */
    public function update(Request $request, int $submission): RedirectResponse
    {
        $record = $this->findEditableSubmission($request, $submission);
        $validated = $this->validatedRelease($request);

        DB::transaction(function () use ($record, $validated) {
            $record->update(['payload' => $validated]);

            $song = $record->song;
            $song->update(['track_title' => $validated['track_title']]);
            // Artist emails may have changed — the union across every
            // submit of the song must stay accurate (spec S11/S6).
            $this->submissions->recomputeArtistEmails($song);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Submission updated.',
        ]);

        return to_route('songs.show', $record->song->song_code);
    }

    /**
     * Spec S11: delete a submit; the song goes away with its last one.
     */
    public function destroy(Request $request, int $submission): RedirectResponse
    {
        $record = $this->findEditableSubmission($request, $submission);
        $song = $record->song;

        DB::transaction(function () use ($record, $song) {
            $record->delete();

            $remaining = $song->submissions()->count();
            if ($remaining === 0) {
                $song->delete();

                return;
            }

            $song->update(['submission_count' => $remaining]);
            $this->submissions->recomputeArtistEmails($song);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Submission deleted.',
        ]);

        // The song disappears with its last submit — go back to the list.
        if (! Song::whereKey($song->id)->exists()) {
            return to_route('songs.index');
        }

        return to_route('songs.show', $song->song_code);
    }

    /**
     * Spec S11: view scope first (404 for strangers), then creator only
     * (403 for artists who can merely see the submit).
     */
    private function findEditableSubmission(Request $request, int $submission): SongSubmission
    {
        $record = SongSubmission::with('song')->findOrFail($submission);

        abort_unless(
            Song::visibleTo($request->user())->whereKey($record->song_id)->exists(),
            404,
        );
        // "editar y borrar solo al que envió el submit" — no artist-email
        // match, no admin bypass (spec S11).
        abort_unless($record->created_by !== null && $record->created_by === $request->user()->id, 403);

        return $record;
    }

    /**
     * Release payload validation shared by store and update (spec S1/S11).
     */
    private function validatedRelease(Request $request): array
    {
        $validated = $request->validate([
            'publishing' => ['required', Rule::in(['afrodise', 'none'])],
            'track_title' => ['required', 'string', 'max:500'],
            'cover_artwork_creative_direction' => ['required', 'string'],
            'audio_link' => ['required', 'string', 'max:2000'],
            'genre' => ['required', 'string', 'max:255'],
            'country_recording' => ['required', 'string', 'max:255'],
            'audio_style' => ['required', Rule::in(['Vocal', 'Instrumental'])],
            'writers' => ['required', 'string'],
            'explicit_content' => ['required', Rule::in(['Yes', 'No'])],
            'is_cover' => ['required', Rule::in(['Yes', 'No'])],
            'original_song_link' => ['nullable', 'string', 'max:2000'],
            'original_track_credits' => ['nullable', 'string'],
            'artists' => ['array'],
            'artists.*.name' => ['required', 'string', 'max:255'],
            'artists.*.real_name' => ['required', 'string', 'max:255'],
            'artists.*.email' => ['required', 'email', 'max:255'],
            'artists.*.role' => ['required', 'string', 'max:255'],
            'artists.*.country' => ['required', 'string', 'max:255'],
            'artists.*.city' => ['required', 'string', 'max:255'],
            'artists.*.estate' => ['required', 'string', 'max:255'],
            'artists.*.photo' => ['required', 'string', 'max:2000'],
            'artists.*.bio' => ['required', 'string'],
            'artists.*.status' => ['required', Rule::in(['New Artist', 'Existing Artist'])],
            'artists.*.links' => ['array'],
        ]);

        // Conditional rule (mirrors the client + ArtistProfile::isComplete):
        // Existing Artist must provide at least one platform link.
        foreach ($validated['artists'] ?? [] as $index => $artist) {
            if ($artist['status'] !== 'Existing Artist') {
                continue;
            }
            $links = array_filter(
                (array) ($artist['links'] ?? []),
                fn ($v) => trim((string) $v) !== '',
            );
            if ($links === []) {
                return back()->withErrors([
                    "artists.{$index}.links" =>
                        'At least one platform link is required for an existing artist.',
                ])->withInput();
            }
            $validated['artists'][$index]['links'] = $links;
        }

        // Cover fields only apply when is_cover=Yes — normalize empties out.
        if ($validated['is_cover'] === 'No') {
            $validated['original_song_link'] = '';
            $validated['original_track_credits'] = '';
        }

        return $validated;
    }
}
