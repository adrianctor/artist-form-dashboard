<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Spec: odd/tasks/in-app-release-form.md, T3, S5-S6.
 *
 * My profile page: shows the artist section, saves it (create or update),
 * and unlocks the dashboard once complete.
 */
class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndUsersSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithoutProfile(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->artistProfile()->delete();

        return $user;
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'artist_name' => 'Aaron Sevilla',
            'real_name' => 'Aaron Sevilla Lopez',
            'role' => 'Primary',
            'country' => 'Argentina',
            'city' => 'Buenos Aires',
            'estate' => 'CABA',
            'photo' => 'https://drive.google.com/photo.jpg',
            'bio' => 'DJ and producer since 2015.',
            'status' => 'New Artist',
            'links' => [],
            // Spec S6 (profile-writers-publishing): publishing gates the profile.
            'publishing' => 'AFRODISE',
            'publishing_custom' => '',
            'ipi_number' => '',
        ], $override);
    }

    public function test_my_profile_page_renders_with_existing_data(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->get(route('my-profile'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MyProfile')
                ->where('profile', null)
                ->where('user.email', $user->email));
    }

    public function test_saving_a_complete_profile_unlocks_the_dashboard(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload())
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('artist_profiles', [
            'user_id' => $user->id,
            'artist_name' => 'Aaron Sevilla',
            'status' => 'New Artist',
        ]);

        // Gate now lets the user through (fresh instance: the stale one has
        // the artistProfile relation cached as null from before the save).
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_incomplete_payload_is_rejected_with_errors(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->from(route('my-profile'))
            ->patch(route('my-profile.update'), $this->validPayload(['bio' => '']))
            ->assertRedirect(route('my-profile'))
            ->assertSessionHasErrors('bio');

        $this->assertFalse($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_existing_artist_requires_at_least_one_platform_link(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload([
                'status' => 'Existing Artist',
                'links' => [],
            ]))
            ->assertSessionHasErrors('links');

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload([
                'status' => 'Existing Artist',
                'links' => ['spotify' => 'https://open.spotify.com/artist/x'],
            ]))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_saving_again_updates_the_existing_profile(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload())
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['artist_name' => 'New Stage Name']))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('artist_profiles', [
            'user_id' => $user->id,
            'artist_name' => 'New Stage Name',
        ]);
        $this->assertSame(1, $user->artistProfile()->count());
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['status' => 'Something Else']))
            ->assertSessionHasErrors('status');
    }

    /* ---- Specs S2/S4/S6 (profile-writers-publishing) ---- */

    public function test_role_must_be_one_of_the_three_select_values(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['role' => 'Producer']))
            ->assertSessionHasErrors('role');

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['role' => 'Remix']))
            ->assertRedirect(route('dashboard'));

        $this->assertSame('Remix', $user->fresh()->artistProfile->role);
    }

    public function test_bio_is_capped_at_500_characters(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['bio' => str_repeat('a', 501)]))
            ->assertSessionHasErrors('bio');

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['bio' => str_repeat('a', 500)]))
            ->assertRedirect(route('dashboard'));
    }

    public function test_publishing_is_required_and_gates_the_profile(): void
    {
        $user = $this->userWithoutProfile();

        // Spec S6: no publishing → validation error AND incomplete profile.
        $this->actingAs($user)
            ->from(route('my-profile'))
            ->patch(route('my-profile.update'), $this->validPayload(['publishing' => '']))
            ->assertRedirect(route('my-profile'))
            ->assertSessionHasErrors('publishing');

        $this->assertFalse($user->fresh()->hasCompleteArtistProfile());

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload())
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_other_publishing_requires_the_custom_company_name(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->from(route('my-profile'))
            ->patch(route('my-profile.update'), $this->validPayload([
                'publishing' => 'OTHER',
                'publishing_custom' => '',
            ]))
            ->assertRedirect(route('my-profile'))
            ->assertSessionHasErrors('publishing_custom');

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload([
                'publishing' => 'OTHER',
                'publishing_custom' => 'Sunset Publishing LLC',
            ]))
            ->assertRedirect(route('dashboard'));

        $profile = $user->fresh()->artistProfile;
        $this->assertSame('OTHER', $profile->publishing);
        $this->assertSame('Sunset Publishing LLC', $profile->publishing_custom);
    }

    public function test_invalid_publishing_value_is_rejected(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['publishing' => 'Something Else']))
            ->assertSessionHasErrors('publishing');
    }

    public function test_ipi_number_is_saved_when_provided(): void
    {
        $user = $this->userWithoutProfile();

        $this->actingAs($user)
            ->patch(route('my-profile.update'), $this->validPayload(['ipi_number' => '00123456789']))
            ->assertRedirect(route('dashboard'));

        $this->assertSame('00123456789', $user->fresh()->artistProfile->ipi_number);
    }
}
