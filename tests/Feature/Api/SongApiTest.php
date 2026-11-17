<?php

namespace Tests\Feature\Api;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SongApiTest extends TestCase
{
    use RefreshDatabase;

    private function apiPayload(array $overrides = []): array
    {
        return array_merge([
            'track_title' => 'Aaron Sevilla, Badrops - Ya Zamani (Original Mix)',
            'cover_artwork_creative_direction' => 'Neon gradients, retro textures',
            'audio_link' => 'https://drive.google.com/file/d/abc',
            'genre' => 'Afro House',
            'country_recording' => 'Argentina',
            'audio_style' => 'Vocal',
            'writers' => 'Aaron Sevilla',
            'explicit_content' => 'No',
            'is_cover' => 'No',
            'artist_count' => 1,
            'artists' => [
                [
                    'name' => 'Aaron Sevilla',
                    'real_name' => 'Aaron Sevilla',
                    'email' => 'aaron@example.com',
                    'role' => 'Producer',
                    'country' => 'Argentina',
                    'city' => 'Buenos Aires',
                    'estate' => 'CABA',
                    'photo' => 'https://drive.google.com/photo.jpg',
                    'bio' => 'Producer and DJ',
                    'status' => 'Existing Artist',
                    'links' => [
                        'spotify' => 'https://open.spotify.com/artist/1',
                        'apple_music' => 'https://music.apple.com/artist/1',
                        'beatport' => 'https://www.beatport.com/artist/1',
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_guest_cannot_create_submission(): void
    {
        $response = $this->postJson('/api/v1/songs', $this->apiPayload());

        $response->assertUnauthorized();
    }

    public function test_new_song_submission_generates_unique_song_code(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/songs', $this->apiPayload());

        $response->assertCreated()
            ->assertJsonStructure(['song_code', 'submission_id', 'version'])
            ->assertJsonPath('version', 1);

        $code = $response->json('song_code');
        $this->assertMatchesRegularExpression('/^SONG-[A-Z0-9]{8}$/', $code);

        $this->assertDatabaseHas('songs', ['song_code' => $code, 'submission_count' => 1]);
        $this->assertDatabaseCount('song_submissions', 1);
    }

    public function test_submission_with_existing_song_code_appends_new_version(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $first = $this->postJson('/api/v1/songs', $this->apiPayload());
        $code = $first->json('song_code');

        $second = $this->postJson('/api/v1/songs', $this->apiPayload([
            'song_code' => $code,
            'genre' => 'Deep House',
        ]));

        $second->assertCreated()
            ->assertJsonPath('song_code', $code)
            ->assertJsonPath('version', 2);

        $this->assertDatabaseCount('songs', 1);
        $this->assertDatabaseCount('song_submissions', 2);
        $this->assertDatabaseHas('songs', ['song_code' => $code, 'submission_count' => 2]);
    }

    public function test_get_returns_latest_submission_payload_for_prefill(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $first = $this->postJson('/api/v1/songs', $this->apiPayload());
        $code = $first->json('song_code');

        $this->postJson('/api/v1/songs', $this->apiPayload([
            'song_code' => $code,
            'genre' => 'Deep House',
            'artists' => [[
                'name' => 'Aaron Sevilla',
                'real_name' => 'Aaron Sevilla',
                'email' => 'aaron@example.com',
                'role' => 'Producer',
                'country' => 'Argentina',
                'city' => 'Buenos Aires',
                'estate' => 'CABA',
                'photo' => 'https://drive.google.com/photo.jpg',
                'bio' => 'Producer and DJ',
                'status' => 'Existing Artist',
                'links' => ['spotify' => 'https://open.spotify.com/artist/1'],
            ]],
        ]));

        $response = $this->getJson("/api/v1/songs/{$code}");

        $response->assertOk()
            ->assertJsonPath('song_code', $code)
            ->assertJsonPath('version', 2)
            ->assertJsonPath('payload.genre', 'Deep House')
            ->assertJsonPath('payload.artists.0.links.spotify', 'https://open.spotify.com/artist/1');
    }

    public function test_get_unknown_song_code_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/songs/SONG-NOEXIST')->assertNotFound();
    }

    public function test_history_endpoint_returns_all_versions_oldest_first(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $first = $this->postJson('/api/v1/songs', $this->apiPayload());
        $code = $first->json('song_code');
        $this->postJson('/api/v1/songs', $this->apiPayload(['song_code' => $code, 'genre' => 'Tech House']));
        $this->postJson('/api/v1/songs', $this->apiPayload(['song_code' => $code, 'genre' => 'Minimal']));

        $response = $this->getJson("/api/v1/songs/{$code}/history");

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.payload.genre', 'Afro House')
            ->assertJsonPath('data.2.payload.genre', 'Minimal');
    }

    public function test_invalid_payload_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/songs', ['track_title' => ''])->assertUnprocessable()
            ->assertJsonValidationErrors(['track_title']);
    }

    public function test_unknown_existing_code_is_rejected_on_submission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/songs', $this->apiPayload([
            'song_code' => 'SONG-DOESNOT',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['song_code']);
    }
}
