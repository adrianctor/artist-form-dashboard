<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('user', 'web');
    }

    public function test_login_page_always_lists_the_three_social_providers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('socialProviders', ['google', 'microsoft', 'apple']));
    }

    public function test_unconfigured_provider_redirects_back_with_a_social_error(): void
    {
        // Microsoft/Apple have no credentials in .env → must not 503; friendly error.
        // (Google is configured locally, so it is exercised separately.)
        $response = $this->get('/auth/microsoft');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('social');
    }

    public function test_configured_google_redirects_to_google_oauth(): void
    {
        config([
            'services.google.client_id' => 'gid',
            'services.google.client_secret' => 'gsecret',
        ]);

        $response = $this->get('/auth/google');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/', $response->headers->get('Location'));
    }

    public function test_unknown_provider_is_still_not_found(): void
    {
        $this->get('/auth/evil')->assertNotFound();
    }

    public function test_unregistered_social_user_is_created_with_user_role_and_lands_on_dashboard(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        $socialiteUser = new SocialiteUser();
        $socialiteUser->map([
            'email' => 'fresh.artist@example.com',
            'name' => 'Fresh Artist',
        ]);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturn($provider = \Mockery::mock());
        $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

        $response = $this->get('/auth/google/callback?provider=google');

        $user = User::where('email', 'fresh.artist@example.com')->firstOrFail();
        $this->assertSame('Fresh Artist', $user->name);
        $this->assertTrue($user->hasRole('user'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertAuthenticatedAs($user);

        $response->assertRedirect(route('dashboard', absolute: false));

        // The OAuth provider vouches for the email: the account must be
        // created verified, otherwise the verified middleware bounces the
        // fresh social signup straight to /email/verify (bug reported by
        // the user: "cuando me registro por google no caigo al dashboard").
        $this->assertNotNull(
            $user->fresh()->email_verified_at,
            'Social signups must not require email verification.'
        );

        // Following the redirect must land on the dashboard, NOT on
        // the email verification notice.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_social_login_verifies_a_previously_unverified_account(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        // Account created earlier via the password register flow (unverified).
        $existing = User::factory()->create([
            'email' => 'was.unverified@example.com',
            'email_verified_at' => null,
        ]);
        $existing->assignRole('user');

        $socialiteUser = new SocialiteUser();
        $socialiteUser->map(['email' => 'was.unverified@example.com', 'name' => 'Was Unverified']);

        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider = \Mockery::mock());
        $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

        $this->get('/auth/google/callback?provider=google')
            ->assertRedirect(route('dashboard', absolute: false));

        // Google proved email ownership — pending verification is cleared.
        $this->assertNotNull($existing->fresh()->email_verified_at);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_registered_social_user_is_logged_in_without_duplicate(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $existing->assignRole('user');

        $socialiteUser = new SocialiteUser();
        $socialiteUser->map(['email' => 'existing@example.com', 'name' => 'Existing User']);

        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider = \Mockery::mock());
        $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

        $this->get('/auth/google/callback?provider=google')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'existing@example.com')->count());
    }
}
