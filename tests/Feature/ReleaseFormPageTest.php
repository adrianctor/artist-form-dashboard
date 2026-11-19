<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Spec: odd/tasks/in-app-release-form.md, T5, S1/S3.
 *
 * The release form lives inside the app (no more WordPress).
 */
class ReleaseFormPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/release-form')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_the_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/release-form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ReleaseForm'));
    }

}
