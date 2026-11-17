<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * OAuth login (spec S4): Google, Microsoft, Apple.
 *
 * Behaviour:
 * - Existing user with the same email → log in.
 * - Unknown email → create an account with a random password, verified,
 *   default `user` role (admins manage elevated roles from the panel).
 * - Deactivated accounts (is_active = false) are rejected.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = [
        'google' => 'google',
        'microsoft' => 'microsoft',
        'apple' => 'apple',
    ];

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);

        // Buttons are hidden when unconfigured; guard direct hits too (S4).
        abort_if(blank(config("services.{$provider}.client_id")), 503, ucfirst($provider).' login is not configured.');

        config(["services.{$provider}.redirect" => route("auth.provider.callback", $provider)]);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);

        config(["services.{$provider}.redirect" => route("auth.provider.callback", $provider)]);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')
                ->withErrors(['email' => ucfirst($provider).' sign-in failed. Please try again.']);
        }

        $email = strtolower((string) ($socialUser->getEmail() ?? ''));

        if ($email === '') {
            return redirect()->route('login')
                ->withErrors(['email' => ucfirst($provider).' did not provide an email address.']);
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $user = User::create([
                'name' => $socialUser->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(40),
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
            $user->assignRole('user');
        }

        if (! $user->is_active) {
            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated.']);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
