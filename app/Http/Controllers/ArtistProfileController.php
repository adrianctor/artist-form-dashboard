<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
}
