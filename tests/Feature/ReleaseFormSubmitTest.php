<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec: odd/tasks/in-app-release-form.md, T6, S1+S3.
 *
 * POST /release-form — session-authenticated submit into the same song
 * pipeline as the API (ownership by artist email), with `publishing`.
 */
class ReleaseFormSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'track_title' => 'Aaron Sevilla - Ya Zamani (Original Mix)',
            'cover_artwork_creative_direction' => 'Warm sunset colors.',
            'audio_link' => 'https://drive.google.com/file/master.wav',
            'genre' => 'Organic House',
            'country_recording' => 'Argentina',
            'audio_style' => 'Vocal',
            // Spec S3: writers are rows of {name, role}.
            'writers' => [
                ['name' => 'Aaron Sevilla', 'role' => 'Primary'],
            ],
            'explicit_content' => 'No',
            'is_cover' => 'No',
            'original_song_link' => '',
            'original_track_credits' => '',
            'artists' => [
                [
                    'name' => 'Aaron Sevilla',
                    'real_name' => 'Aaron Sevilla Lopez',
                    'email' => 'owner.artist@example.com',
                    'role' => 'Primary',
                    'country' => 'Argentina',
                    'city' => 'Buenos Aires',
                    'estate' => 'CABA',
                    'photo' => 'https://drive.google.com/photo.jpg',
                    'bio' => 'DJ since 2015.',
                    'status' => 'New Artist',
                    // Spec S5: publishing lives per artist now.
                    'publishing' => 'AFRODISE',
                    'publishing_custom' => '',
                    'ipi_number' => '',
                    'links' => [],
                ],
            ],
        ], $override);
    }

    public function test_guests_cannot_submit(): void
    {
        $this->post('/release-form', $this->validPayload())
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_submit_and_song_is_created(): void
    {
        $user = User::factory()->create();
        // The artist already has a dashboard account → song is theirs.
        User::factory()->create(['email' => 'owner.artist@example.com']);

        $this->actingAs($user)
            ->post('/release-form', $this->validPayload())
            ->assertRedirect(route('calendar'));

        $song = Song::first();
        $this->assertNotNull($song);
        $this->assertSame(
            'Aaron Sevilla - Ya Zamani (Original Mix)',
            $song->track_title,
        );
        $this->assertSame(1, $song->submission_count);

        // Ownership: artist email matches a dashboard user (spec S4/S5).
        $owner = User::where('email', 'owner.artist@example.com')->first();
        $this->assertNotNull($owner);
        $this->assertSame($owner->id, $song->user_id);

        // Spec S5: publishing rides inside the payload per artist.
        $payload = $song->submissions()->first()->payload;
        $this->assertSame('AFRODISE', $payload['artists'][0]['publishing']);
    }

    public function test_artist_publishing_options_are_accepted(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['artists'][0]['publishing'] = 'COPYRIGHT CONTROL';

        $this->actingAs($user)
            ->post('/release-form', $payload)
            ->assertRedirect(route('calendar'));

        $stored = Song::first()->submissions()->first()->payload;
        $this->assertSame('COPYRIGHT CONTROL', $stored['artists'][0]['publishing']);
    }

    public function test_invalid_artist_publishing_value_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['artists'][0]['publishing'] = 'other';

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $payload)
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('artists.0.publishing');
    }

    public function test_other_publishing_requires_the_custom_company_name(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['artists'][0]['publishing'] = 'OTHER';
        $payload['artists'][0]['publishing_custom'] = '';

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $payload)
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('artists.0.publishing_custom');

        $payload['artists'][0]['publishing_custom'] = 'Sunset Publishing LLC';
        $this->actingAs($user)
            ->post('/release-form', $payload)
            ->assertRedirect(route('calendar'));

        $stored = Song::first()->submissions()->first()->payload;
        $this->assertSame('Sunset Publishing LLC', $stored['artists'][0]['publishing_custom']);
    }

    public function test_missing_writer_role_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $this->validPayload([
                'writers' => [['name' => 'Aaron Sevilla', 'role' => 'Something Else']],
            ]))
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('writers.0.role');
    }

    public function test_empty_writers_array_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $this->validPayload(['writers' => []]))
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('writers');
    }

    public function test_invalid_artist_role_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['artists'][0]['role'] = 'Producer';

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $payload)
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('artists.0.role');
    }

    /* ---- Regression probes from the independent verifier (S5/S3) ---- */

    public function test_legacy_top_level_publishing_key_is_ignored_and_not_stored(): void
    {
        // Spec S5: the global publishing radio is gone; the legacy key must
        // neither fail validation nor ride into the stored payload.
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['publishing'] = 'afrodise';

        $this->actingAs($user)->post('/release-form', $payload)
            ->assertRedirect(route('calendar'));

        $stored = Song::first()->submissions()->first()->payload;
        $this->assertArrayNotHasKey('publishing', $stored);
        $this->assertSame('AFRODISE', $stored['artists'][0]['publishing']);
    }

    public function test_missing_artist_publishing_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['artists'][0]['publishing'] = null;

        $this->actingAs($user)->from(route('release-form'))
            ->post('/release-form', $payload)
            ->assertSessionHasErrors('artists.0.publishing');
        $this->assertSame(0, Song::count()); // rejected request stores nothing
    }

    public function test_legacy_free_text_writers_string_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from(route('release-form'))
            ->post('/release-form', $this->validPayload(['writers' => 'Free Text Writer']))
            ->assertSessionHasErrors('writers');
    }

    public function test_bio_over_500_characters_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['artists'][0]['bio'] = str_repeat('a', 501);

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $payload)
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('artists.0.bio');
    }

    public function test_missing_track_title_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $this->validPayload(['track_title' => '']))
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('track_title');

        $this->assertSame(0, Song::count());
    }

    public function test_existing_artist_without_links_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['artists'][0]['status'] = 'Existing Artist';
        $payload['artists'][0]['links'] = [];

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $payload)
            ->assertSessionHasErrors('artists.0.links');
    }

    public function test_submit_lands_on_the_calendar(): void
    {
        // Spec S5: after a successful submit the user is taken to the
        // calendar where the new track appears on today's date.
        $user = User::factory()->create();
        User::factory()->create(['email' => 'owner.artist@example.com']);

        $this->actingAs($user)
            ->post('/release-form', $this->validPayload())
            ->assertRedirect(route('calendar'));

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Calendar'));
    }
}
