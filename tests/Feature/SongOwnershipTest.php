<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Spec: users only see songs they registered (via the form's artist emails);
 * admins see everything.
 */
class SongOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('user', 'web');
    }

    private function makeUser(string $email, string $role = 'user'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);

        return $user;
    }

    private function submitSong(array $overrides = []): array
    {
        Sanctum::actingAs(User::factory()->create());

        $payload = array_merge([
            'track_title' => 'Owned Song',
            'genre' => 'House',
            'artists' => [[
                'name' => 'Someone',
                'email' => 'owner@example.com',
            ]],
        ], $overrides);

        $response = $this->postJson('/api/v1/songs', $payload);
        $response->assertCreated();

        return $response->json();
    }

    public function test_song_is_owned_by_the_matching_artist_email_user(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $this->makeUser('other@example.com');

        $result = $this->submitSong();

        $song = Song::where('song_code', $result['song_code'])->firstOrFail();
        $this->assertSame($owner->id, $song->user_id);
    }

    public function test_song_without_matching_artist_email_is_visible_only_to_admin(): void
    {
        $this->makeUser('nobody@example.com');
        $result = $this->submitSong(['artists' => [['name' => 'X', 'email' => 'stranger@no-account.com']]]);

        $song = Song::where('song_code', $result['song_code'])->firstOrFail();
        $this->assertNull($song->user_id);
    }

    public function test_user_only_sees_their_own_songs_in_the_index(): void
    {
        $mine = Song::create(['song_code' => 'SONG-MINE001', 'track_title' => 'Mine', 'submission_count' => 1]);
        $theirs = Song::create(['song_code' => 'SONG-THEIRS01', 'track_title' => 'Theirs', 'submission_count' => 1]);
        $mine->update(['user_id' => $this->makeUser('me@example.com')->id]);
        $theirs->update(['user_id' => $this->makeUser('you@example.com')->id]);

        $response = $this->actingAs(User::where('email', 'me@example.com')->firstOrFail())->get('/songs');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('songs/Index')
            ->has('songs.data', 1)
            ->where('songs.data.0.song_code', 'SONG-MINE001')
        );
    }

    public function test_admin_sees_all_songs_in_the_index(): void
    {
        Song::create(['song_code' => 'SONG-A0000001', 'track_title' => 'A', 'submission_count' => 1]);
        Song::create(['song_code' => 'SONG-B0000001', 'track_title' => 'B', 'submission_count' => 1]);

        $response = $this->actingAs($this->makeUser('boss@example.com', 'admin'))->get('/songs');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('songs.data', 2));
    }

    public function test_user_cannot_open_a_song_they_do_not_own(): void
    {
        $foreign = Song::create(['song_code' => 'SONG-FOREIGN1', 'track_title' => 'Foreign', 'submission_count' => 1]);
        $foreign->update(['user_id' => $this->makeUser('someoneelse@example.com')->id]);

        $this->actingAs($this->makeUser('me2@example.com'))
            ->get('/songs/SONG-FOREIGN1')
            ->assertNotFound();
    }

    public function test_admin_can_open_any_song(): void
    {
        $foreign = Song::create(['song_code' => 'SONG-OPEN001', 'track_title' => 'Open', 'submission_count' => 1]);
        SongSubmission::create([
            'song_id' => $foreign->id,
            'track_title' => 'Open v1',
            'payload' => ['genre' => 'Techno'],
        ]);

        $this->actingAs($this->makeUser('boss2@example.com', 'admin'))
            ->get('/songs/SONG-OPEN001')
            ->assertOk();
    }
}
