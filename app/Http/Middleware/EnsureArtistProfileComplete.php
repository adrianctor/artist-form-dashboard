<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec S5 (odd/tasks/in-app-release-form.md, T2): a role=user without a
 * complete artist profile cannot access any dashboard section — only the
 * My profile page. Admin role is exempt (not an artist).
 */
class EnsureArtistProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Guests fall through: 'auth' runs first in every gated group.
        if ($user === null) {
            return $next($request);
        }

        if ($user->hasRole('admin') || $user->hasCompleteArtistProfile()) {
            return $next($request);
        }

        // The profile page (and its save endpoint) stays reachable so the
        // user can actually complete the missing fields.
        if ($request->routeIs('my-profile*')) {
            return $next($request);
        }

        return redirect()->route('my-profile');
    }
}
