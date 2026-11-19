<?php

namespace App\Http\Controllers;

use App\Mail\SetPasswordSetupLink;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-time password setup for users who never chose one (spec S1/S2):
 * an emailed, signed, short-lived link replaces the impossible
 * "current password" question. The link dies as soon as a password
 * exists (410), so it can never be used to bypass current_password.
 */
class PasswordSetupController extends Controller
{
    /** Ask for the setup link (authenticated, throttled). */
    public function sendLink(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->password_set_at !== null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'This account already has a password — use "Update password" instead.',
            ]);

            return back();
        }

        $setupUrl = URL::temporarySignedRoute(
            'password.setup',
            now()->addMinutes(60),
            ['user' => $user->id],
        );

        Mail::to($user->email)->send(new SetPasswordSetupLink($user, $setupUrl));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Setup link sent to '.$user->email.' — valid for 60 minutes.',
        ]);

        return back();
    }

    /** Public form behind a valid, unexpired signature. */
    public function show(Request $request): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $user = User::findOrFail($request->query('user'));
        abort_if($user->password_set_at !== null, 410, 'This setup link has already been used.');

        return Inertia::render('auth/SetPassword', [
            'email' => $user->email,
        ]);
    }

    /** Persist the first password; the link becomes useless afterwards. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $user = User::findOrFail($request->query('user'));
        abort_if($user->password_set_at !== null, 410, 'This setup link has already been used.');

        $data = $request->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'password_set_at' => now(),
        ])->save();

        if (Auth::check() && Auth::id() === $user->id) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Password set successfully.',
            ]);

            return to_route('security.edit');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Password set. You can now sign in.',
        ]);

        return to_route('login');
    }
}
