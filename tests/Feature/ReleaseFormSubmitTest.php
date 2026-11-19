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
            'publishing' => 'afrodise',
            'track_title' => 'Aaron Sevilla - Ya Zamani (Original Mix)',
            'cover_artwork_creative_direction' => 'Warm sunset colors.',
            'audio_link' => 'https://drive.google.com/file/master.wav',
            'genre' => 'Organic House',
            'country_recording' => 'Argentina',
            'audio_style' => 'Vocal',
            'writers' => 'Aaron Sevilla',
            'explicit_content' => 'No',
            'is_cover' => 'No',
            'original_song_link' => '',
            'original_track_credits' => '',
            'artists' => [
                [
                    'name' => 'Aaron Sevilla',
                    'real_name' => 'Aaron Sevilla Lopez',
                    'email' => 'owner.artist@example.com',
                    'role' => 'Producer',
                    'country' => 'Argentina',
                    'city' => 'Buenos Aires',
                    'estate' => 'CABA',
                    'photo' => 'https://drive.google.com/photo.jpg',
                    'bio' => 'DJ since 2015.',
                    'status' => 'New Artist',
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

        // Publishing rides inside the payload (spec S3).
        $payload = $song->submissions()->first()->payload;
        $this->assertSame('afrodise', $payload['publishing']);
    }

    public function test_publishing_none_option_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/release-form', $this->validPayload([
                'publishing' => 'none',
                'artists' => [],
            ]))
            ->assertRedirect(route('calendar'));

        $payload = Song::first()->submissions()->first()->payload;
        $this->assertSame('none', $payload['publishing']);
    }

    public function test_invalid_publishing_value_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('release-form'))
            ->post('/release-form', $this->validPayload(['publishing' => 'other']))
            ->assertRedirect(route('release-form'))
            ->assertSessionHasErrors('publishing');
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
