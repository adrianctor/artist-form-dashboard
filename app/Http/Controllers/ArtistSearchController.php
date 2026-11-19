<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spec S4 (odd/tasks/in-app-release-form.md, T4): search registered users
 * by name or email to autofill artist blocks in the release form. Only
 * users with a COMPLETE artist profile are returned.
 */
class ArtistSearchController extends Controller
{
    private const LIMIT = 10;

    /** Candidates fetched before the completeness filter. */
    private const CANDIDATE_MULTIPLIER = 5;

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $like = '%'.$q.'%';

        $candidates = User::query()
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('artistProfile', function ($profile) use ($like) {
                        $profile->where('artist_name', 'like', $like)
                            ->orWhere('real_name', 'like', $like);
                    });
            })
            ->with('artistProfile')
            ->orderBy('name')
            ->limit(self::LIMIT * self::CANDIDATE_MULTIPLIER)
            ->get();

        $results = $candidates
            ->filter(fn (User $user) => $user->hasCompleteArtistProfile())
            ->take(self::LIMIT)
            ->map(fn (User $user) => $this->serialize($user))
            ->values();

        return response()->json($results);
    }

    /**
     * Shape consumed by the form's autofill (spec S4).
     */
    private function serialize(User $user): array
    {
        $profile = $user->artistProfile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'artist_name' => $profile->artist_name,
            'real_name' => $profile->real_name,
            'role' => $profile->role,
            'country' => $profile->country,
            'city' => $profile->city,
            'estate' => $profile->estate,
            'photo' => $profile->photo,
            'bio' => $profile->bio,
            'status' => $profile->status,
            'links' => $profile->links ?? [],
        ];
    }
}
