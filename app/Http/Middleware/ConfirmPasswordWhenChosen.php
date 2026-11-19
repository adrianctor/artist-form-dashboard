<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec S3 (odd/tasks/first-password-setup.md): re-confirming the password
 * only makes sense when the user HAS one. Social signups
 * (password_set_at = NULL) never chose a password, so they must reach the
 * security settings (and the first-time setup flow) without being asked
 * for a password they do not have. Everyone else gets the regular
 * RequirePassword behaviour.
 */
class ConfirmPasswordWhenChosen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->password_set_at === null) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle($request, $next);
    }
}
