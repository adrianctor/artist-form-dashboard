<?php

namespace App\Http\Controllers;

use App\Mail\ReleaseFormSubmitted;
use App\Services\SongSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
     * Success redirects back with ?submitted=1 (the form shows its success
     * box; the Catalogo identifier is NOT shown — spec S7, pending).
     */
    public function store(Request $request): RedirectResponse
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

        $this->submissions->submit($validated);

        // Spec S2: system email replaces EmailJS (same recipients). The
        // mailable's envelope carries to/cc/replyTo.
        Mail::send(new ReleaseFormSubmitted($validated));

        return redirect()
            ->route('release-form', ['submitted' => 1])
            ->with('toast', [
                'type' => 'success',
                'message' => 'Release submitted successfully.',
            ]);
    }
}
