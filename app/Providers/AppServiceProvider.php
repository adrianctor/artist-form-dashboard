<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSocialiteProviders();
    }

    /**
     * SocialiteProviders for Microsoft and Apple (Google ships with Socialite core).
     * Spec S4: login with Gmail, Microsoft and Apple.
     */
    protected function configureSocialiteProviders(): void
    {
        Event::listen(SocialiteWasCalled::class, MicrosoftExtendSocialite::class.'@handle');
        // Spec S2: resetting a password by email means the user now knows
        // their password — they leave the first-time-setup flow.
        Event::listen(PasswordReset::class, function (PasswordReset $event) {
            $event->user->forceFill(['password_set_at' => now()])->save();
        });
        Event::listen(SocialiteWasCalled::class, AppleExtendSocialite::class.'@handle');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
