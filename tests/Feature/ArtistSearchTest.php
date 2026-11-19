<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec: odd/tasks/in-app-release-form.md, T4, S4.
 *
 * GET /artist-search?q= — search registered users by name or email,
 * only those with a COMPLETE artist profile (incomplete ones are hidden).
 */
class ArtistSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompleteArtist(string $name, string $email): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        // Factory already created a complete profile; customize artist_name.
        $user->artistProfile->update(['artist_name' => $name]);

        return $user;
    }

    public function test_guests_cannot_search(): void
    {
        $this->getJson('/artist-search?q=aaron')->assertUnauthorized();
    }

    public function test_search_matches_by_artist_name(): void
    {
        $this->makeCompleteArtist('Aaron Sevilla', 'aaron@example.com');
        $auth = User::factory()->create();

        $this->actingAs($auth)
            ->getJson('/artist-search?q=aaron')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.artist_name', 'Aaron Sevilla');
    }

    public function test_search_matches_by_user_email(): void
    {
        $this->makeCompleteArtist('Someone Else', 'unique.mail@example.com');
        $auth = User::factory()->create();

        $this->actingAs($auth)
            ->getJson('/artist-search?q=unique.mail')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.email', 'unique.mail@example.com');
    }

    public function test_search_matches_by_real_name(): void
    {
        $user = $this->makeCompleteArtist('Stage Name', 'stage@example.com');
        $user->artistProfile->update(['real_name' => 'Juan Perez']);
        $auth = User::factory()->create();

        $this->actingAs($auth)
            ->getJson('/artist-search?q=juan perez')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_incomplete_profiles_are_excluded(): void
    {
        $incomplete = User::factory()->create(['name' => 'Ghost Artist']);
        $incomplete->artistProfile()->delete();
        // Recreate with missing bio → incomplete.
        ArtistProfile::create([
            'user_id' => $incomplete->id,
            'artist_name' => 'Ghost Artist',
            'real_name' => 'Ghost',
            'role' => 'DJ',
            'country' => 'AR',
            'city' => 'BA',
            'estate' => 'CABA',
            'photo' => 'https://x.com/p.jpg',
            'bio' => '',
            'status' => 'New Artist',
            'links' => [],
        ]);

        $auth = User::factory()->create();

        $this->actingAs($auth)
            ->getJson('/artist-search?q=ghost')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_empty_query_returns_nothing(): void
    {
        $this->makeCompleteArtist('Aaron Sevilla', 'aaron@example.com');
        $auth = User::factory()->create();

        $this->actingAs($auth)
            ->getJson('/artist-search?q=')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_result_shape_contains_profile_fields_for_autofill(): void
    {
        $this->makeCompleteArtist('Aaron Sevilla', 'aaron@example.com');
        $auth = User::factory()->create();

        $this->actingAs($auth)
            ->getJson('/artist-search?q=aaron')
            ->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id', 'name', 'email', 'artist_name', 'real_name',
                    'role', 'country', 'city', 'estate', 'photo', 'bio',
                    'status', 'links',
                ],
            ]);
    }

    public function test_search_is_limited_to_ten_results(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->makeCompleteArtist("Common Name $i", "common$i@example.com");
        }
        $auth = User::factory()->create();

        $this->actingAs($auth)
            ->getJson('/artist-search?q=common')
            ->assertOk()
            ->assertJsonCount(10);
    }
}
