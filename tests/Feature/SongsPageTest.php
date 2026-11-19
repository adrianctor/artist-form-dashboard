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
            ->where('submissions.0.version', 2)
            ->where('submissions.1.track_title', 'Northern Lights (v1)')
            ->where('submissions.1.version', 1)
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
