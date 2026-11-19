<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_songs_index(): void
    {
        $this->get('/songs')->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_songs_index(): void
    {
        $user = User::factory()->create();
        $song = Song::create([
            'song_code' => 'SONG-INDEX01',
            'user_id' => $user->id,
            'track_title' => 'Midnight Drive',
            'submission_count' => 1,
        ]);

        $response = $this->actingAs($user)->get('/songs');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('songs/Index')
            ->has('songs.data', 1)
            ->where('songs.data.0.song_code', $song->song_code)
            ->where('songs.data.0.track_title', $song->track_title)
            ->where('songs.data.0.submission_count', 1)
        );
    }

    public function test_authenticated_users_can_view_a_song_with_its_submissions(): void
    {
        $user = User::factory()->create();
        $song = Song::create([
            'song_code' => 'SONG-DETAIL01',
            'user_id' => $user->id,
            'track_title' => 'Northern Lights',
            'submission_count' => 2,
        ]);

        SongSubmission::create([
            'song_id' => $song->id,
            'track_title' => 'Northern Lights (v1)',
            'payload' => ['genre' => 'Indie'],
        ]);
        SongSubmission::create([
            'song_id' => $song->id,
            'track_title' => 'Northern Lights (v2)',
            'payload' => [
                'genre' => 'Dream pop',
                'artists' => [['name' => 'Avery Lane', 'link' => 'https://example.com/avery']],
            ],
        ]);

        $response = $this->actingAs($user)->get('/songs/'.$song->song_code);

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('songs/Show')
            ->where('song.song_code', $song->song_code)
            ->where('song.track_title', $song->track_title)
            ->where('song.submission_count', 2)
            ->has('submissions', 2)
            ->where('submissions.0.track_title', 'Northern Lights (v2)')
            ->where('submissions.0.payload.genre', 'Dream pop')
            // Spec S10: no "Version N" in a submit's info.
            ->missing('submissions.0.version')
            ->missing('submissions.1.version')
            ->where('submissions.1.track_title', 'Northern Lights (v1)')
        );
    }

    public function test_show_shapes_artist_links_as_pairs_never_json(): void
    {
        // Spec S13: platform links leave the payload as {label,url} pairs so
        // the page renders real anchors instead of a JSON blob.
        $user = User::factory()->create();
        $song = Song::create([
            'song_code' => 'SONG-LINKS01',
            'user_id' => $user->id,
            'track_title' => 'Links Song',
            'submission_count' => 1,
        ]);
        SongSubmission::create([
            'song_id' => $song->id,
            'track_title' => 'Links Song',
            'payload' => [
                'genre' => 'House',
                'artists' => [
                    [
                        'name' => 'Link Artist',
                        'email' => 'link.artist@example.com',
                        'bio' => 'Bio here',
                        'links' => [
                            'spotify' => 'https://open.spotify.com/artist/xyz',
                            'apple_music' => '',
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get('/songs/SONG-LINKS01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Structured links: label + url, empty ones dropped.
                ->where('submissions.0.artists.0.name', 'Link Artist')
                ->has('submissions.0.artists.0.links', 1)
                ->where('submissions.0.artists.0.links.0.label', 'Spotify')
                ->where('submissions.0.artists.0.links.0.url', 'https://open.spotify.com/artist/xyz')
                // The name is the card heading, not a duplicated row, and the
                // raw links key never reaches the fields list as JSON.
                ->where('submissions.0.artists.0.fields', function ($fields) {
                    // AssertableInertia hands collections to the closure.
                    $labels = $fields->pluck('label');

                    return ! $labels->contains('Name')
                        && ! $labels->contains('Links')
                        && $labels->contains('Bio');
                })
            );
    }

    public function test_show_delivers_the_full_submission_payload(): void
    {
        // Spec S7: the history must show everything that was filled in the
        // form (same data the calendar links to).
        $song = Song::create([
            'song_code' => 'SONG-FULL99',
            'track_title' => 'Full Payload Song',
            'submission_count' => 1,
            'artist_emails' => ',full.artist@example.com,',
        ]);
        $song->submissions()->create([
            'track_title' => 'Full Payload Song',
            'payload' => [
                'publishing' => 'afrodise',
                'track_title' => 'Full Payload Song',
                'genre' => 'Deep House',
                'writers' => 'Some Writer',
                'explicit_content' => 'No',
                'is_cover' => 'No',
                'artists' => [
                    [
                        'name' => 'Full Artist',
                        'email' => 'full.artist@example.com',
                        'bio' => 'Full bio here',
                        'status' => 'New Artist',
                        'links' => ['spotify' => 'https://open.spotify.com/x'],
                    ],
                ],
            ],
        ]);

        $this->actingAs(User::factory()->create(['email' => 'full.artist@example.com']))
            ->get('/songs/SONG-FULL99')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('submissions.0.payload.genre', 'Deep House')
                ->where('submissions.0.payload.writers', 'Some Writer')
                ->where('submissions.0.payload.artists.0.email', 'full.artist@example.com')
                ->where('submissions.0.payload.artists.0.links.spotify', 'https://open.spotify.com/x')
            );
    }

    public function test_unknown_song_codes_return_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/songs/SONG-UNKNOWN')
            ->assertNotFound();
    }
}
