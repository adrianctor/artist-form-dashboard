<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec: odd/tasks/in-app-release-form.md, T1, S5.
 *
 * artist_profiles table (1:1 users) + completeness rules:
 * all artist fields non-empty; links required only when status = Existing Artist.
 */
class ArtistProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The factory auto-creates a complete profile (spec S5 gate); these tests
     * manage profiles manually, so start from a clean user.
     */
    private function userWithoutProfile(): User
    {
        $user = User::factory()->create();
        $user->artistProfile()->delete();

        return $user;
    }

    private function validAttrs(User $user, array $override = []): array
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
            // Spec S6 (profile-writers-publishing): part of the gate.
            'publishing' => 'AFRODISE',
            'publishing_custom' => '',
            'ipi_number' => '',
        ], $override);
    }

    public function test_artist_profile_can_be_created_for_a_user(): void
    {
        $user = $this->userWithoutProfile();
        $profile = ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user))
        );

        $this->assertDatabaseHas('artist_profiles', [
            'user_id' => $user->id,
            'artist_name' => 'Aaron Sevilla',
            'status' => 'New Artist',
        ]);
        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_user_without_profile_is_incomplete(): void
    {
        $user = $this->userWithoutProfile();

        $this->assertFalse($user->hasCompleteArtistProfile());
        $this->assertNull($user->artistProfile);
    }

    public function test_profile_with_missing_required_fields_is_incomplete(): void
    {
        $user = $this->userWithoutProfile();
        ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user, ['bio' => '']))
        );

        $this->assertFalse($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_new_artist_does_not_require_platform_links(): void
    {
        $user = $this->userWithoutProfile();
        ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user, [
                'status' => 'New Artist',
                'links' => [],
            ]))
        );

        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_existing_artist_requires_platform_links(): void
    {
        $user = $this->userWithoutProfile();
        ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user, [
                'status' => 'Existing Artist',
                'links' => [],
            ]))
        );

        $this->assertFalse($user->fresh()->hasCompleteArtistProfile());

        $profile = $user->fresh()->artistProfile;
        $profile->update(['links' => ['spotify' => 'https://open.spotify.com/artist/x']]);
        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_email_comes_from_the_users_table_not_the_profile(): void
    {
        $user = User::factory()->create(['email' => 'artist@example.com']);
        $user->artistProfile()->delete();
        ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user))
        );

        $profile = $user->fresh()->artistProfile;
        $this->assertSame('artist@example.com', $profile->email);
        $this->assertArrayNotHasKey('email', $profile->getAttributes());
    }

    /* ---- Spec S6 (profile-writers-publishing): publishing in the gate ---- */

    public function test_profile_without_publishing_is_incomplete(): void
    {
        $user = $this->userWithoutProfile();
        ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user, ['publishing' => null]))
        );

        $this->assertFalse($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_other_publishing_without_custom_name_is_incomplete(): void
    {
        $user = $this->userWithoutProfile();
        ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user, [
                'publishing' => 'OTHER',
                'publishing_custom' => '  ',
            ]))
        );

        $this->assertFalse($user->fresh()->hasCompleteArtistProfile());

        $user->fresh()->artistProfile->update(['publishing_custom' => 'Sunset Publishing LLC']);
        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_other_publishing_with_custom_name_is_complete(): void
    {
        $user = $this->userWithoutProfile();
        ArtistProfile::create(
            array_merge(['user_id' => $user->id], $this->validAttrs($user, [
                'publishing' => 'OTHER',
                'publishing_custom' => 'Sunset Publishing LLC',
            ]))
        );

        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }

    public function test_profile_with_legacy_role_value_still_passes_the_gate(): void
    {
        // Spec S2: a profile saved before the 3-value select stays gate-valid;
        // only editing forces Primary/Featuring/Remix.
        $user = $this->userWithoutProfile();
        ArtistProfile::create(array_merge(['user_id' => $user->id], $this->validAttrs($user, ['role' => 'Producer'])));

        $this->assertTrue($user->fresh()->hasCompleteArtistProfile());
    }
}
