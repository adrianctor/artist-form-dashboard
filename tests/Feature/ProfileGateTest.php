<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Spec: odd/tasks/in-app-release-form.md, T2, S5.
 *
 * Gate: role=user without a complete artist profile cannot access any
 * dashboard section — only the My profile page. Admin role is exempt.
 */
class ProfileGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndUsersSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUserWithProfile(array $profileOverride = []): User
    {
        // Factory already creates a complete profile (spec S5).
        $user = User::factory()->create();
        $user->assignRole('user');
        if ($profileOverride !== []) {
            $user->artistProfile->update($profileOverride);
        }

        return $user;
    }

    private function makeUserWithoutProfile(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->artistProfile()->delete();

        return $user;
    }

    public function test_guest_is_redirected_to_login_before_the_profile_gate(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_user_without_profile_cannot_access_dashboard(): void
    {
        $user = $this->makeUserWithoutProfile();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('my-profile'));
    }

    public function test_user_with_incomplete_profile_cannot_access_dashboard(): void
    {
        $user = $this->makeUserWithProfile(['bio' => '']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('my-profile'));
    }

    public function test_user_with_complete_profile_can_access_dashboard(): void
    {
        $user = $this->makeUserWithProfile();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_admin_without_profile_is_exempt_from_the_gate(): void
    {
        $admin = User::where('email', 'label@admin.com')->firstOrFail();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_user_without_profile_can_access_the_my_profile_page_itself(): void
    {
        $user = $this->makeUserWithoutProfile();

        $this->actingAs($user)->get(route('my-profile'))->assertOk();
    }

    public function test_user_without_profile_is_redirected_from_songs_calendar_users_and_settings(): void
    {
        $user = $this->makeUserWithoutProfile();

        foreach (['/songs', '/calendar', '/users', '/settings/profile'] as $uri) {
            $this->actingAs($user)
                ->get($uri)
                ->assertRedirect(route('my-profile'));
        }
    }

    public function test_user_with_complete_profile_keeps_access_to_other_sections(): void
    {
        $user = $this->makeUserWithProfile();

        $this->actingAs($user)->get('/songs')->assertOk();
    }
}
