<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

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
        // No OAuth credentials in .env → must not 503; show a friendly error.
        $response = $this->get('/auth/google');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('social');
    }

    public function test_unknown_provider_is_still_not_found(): void
    {
        $this->get('/auth/evil')->assertNotFound();
    }
}
