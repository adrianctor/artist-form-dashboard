<?php

namespace App\Http\Controllers;

use App\Models\ArtistProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * My profile (spec S6): the artist section of the release form — create or
 * edit the mandatory artist profile (spec S5).
 */
class ArtistProfileController extends Controller
{
    public function show(Request $request): InertiaResponse
    {
        return Inertia::render('MyProfile', [
            'profile' => $request->user()->artistProfile,
            'user' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    /**
     * Spec S5 (gate) + specs S1/S2/S4/S6 of profile-writers-publishing:
     * every artist field is required; role is a 3-value select; bio is
     * capped at 500; publishing is required (custom name only when OTHER);
     * platform links are required only when status is "Existing Artist"
     * (same conditional rule as the release form).
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'artist_name' => ['required', 'string', 'max:255'],
            'real_name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['Primary', 'Featuring', 'Remix'])],
            'country' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'estate' => ['required', 'string', 'max:255'],
            'photo' => ['required', 'string', 'max:2048'],
            'bio' => ['required', 'string', 'max:500'],
            'status' => ['required', Rule::in(['New Artist', 'Existing Artist'])],
            'publishing' => ['required', Rule::in(['AFRODISE', 'COPYRIGHT CONTROL', 'OTHER'])],
            'publishing_custom' => ['nullable', 'string', 'max:255'],
            'ipi_number' => ['nullable', 'string', 'max:50'],
            'links' => ['array'],
            'links.spotify' => ['nullable', 'string', 'max:2048'],
            'links.apple_music' => ['nullable', 'string', 'max:2048'],
            'links.beatport' => ['nullable', 'string', 'max:2048'],
            'links.youtube' => ['nullable', 'string', 'max:2048'],
            'links.instagram' => ['nullable', 'string', 'max:2048'],
            'links.facebook' => ['nullable', 'string', 'max:2048'],
            'links.x' => ['nullable', 'string', 'max:2048'],
            'links.tiktok' => ['nullable', 'string', 'max:2048'],
            'links.soundcloud' => ['nullable', 'string', 'max:2048'],
            'links.songkick' => ['nullable', 'string', 'max:2048'],
            'links.bandsintown' => ['nullable', 'string', 'max:2048'],
            'links.website' => ['nullable', 'string', 'max:2048'],
        ]);

        // Conditional rule (mirrors ArtistProfile::isComplete): Existing
        // Artist must provide at least one platform link.
        if ($data['status'] === 'Existing Artist') {
            $nonEmpty = array_filter($data['links'] ?? [], fn ($v) => trim((string) $v) !== '');
            if ($nonEmpty === []) {
                return back()->withErrors([
                    'links' => 'At least one platform link is required for an existing artist.',
                ])->withInput();
            }
        } else {
            // New Artist: links are optional — normalize to only non-empty.
            $data['links'] = array_filter($data['links'] ?? [], fn ($v) => trim((string) $v) !== '');
        }

        // Spec S6: the custom company name is required only when the
        // publishing select is OTHER; otherwise normalize empties out.
        if ($data['publishing'] === 'OTHER') {
            if (trim((string) ($data['publishing_custom'] ?? '')) === '') {
                return back()->withErrors([
                    'publishing_custom' => 'The publishing company name is required when publishing is OTHER.',
                ])->withInput();
            }
        } else {
            $data['publishing_custom'] = '';
        }
        $data['ipi_number'] = trim((string) ($data['ipi_number'] ?? ''));

        $request->user()->artistProfile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $data,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Artist profile saved.']);

        return to_route('dashboard');
    }
}
