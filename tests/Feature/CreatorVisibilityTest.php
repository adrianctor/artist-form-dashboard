<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec: odd/tasks/form-delivery-and-scale.md, T2, S6.
 *
 * A song is visible to: admins, its owner (artist-email match), the creator
 * of any of its submits, and every dashboard user whose email appears as an
 * artist on any submit (songs.artist_emails list).
 */
class CreatorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndUsersSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser(string $email, string $role = 'user'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);

        return $user;
    }

    private function makeSong(array $attrs = []): Song
    {
        return Song::create(array_merge([
            'song_code' => 'SONG-VIS'.random_int(1000, 9999),
            'track_title' => 'Visible Song',
            'submission_count' => 1,
        ], $attrs));
    }

    public function test_creator_sees_the_song_even_without_artist_email_match(): void
    {
        $creator = $this->makeUser('creator@example.com');
        $song = $this->makeSong([
            'user_id' => null,
            'artist_emails' => ',someone.else@example.com,',
        ]);
        SongSubmission::create([
            'song_id' => $song->id,
            'payload' => ['track_title' => 'Visible Song'],
            'track_title' => 'Visible Song',
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator)
            ->get(route('songs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('songs.data.0.song_code', $song->song_code));

        $this->actingAs($creator)
            ->get(route('songs.show', $song->song_code))
            ->assertOk();
    }

    public function test_every_artist_email_on_any_submit_sees_the_song(): void
    {
        // Second artist (not the stored owner) must still see the song —
        // artist_emails holds the union across all submits (spec S6).
        $secondArtist = $this->makeUser('second.artist@example.com');
        $song = $this->makeSong([
            'user_id' => User::factory()->create(['email' => 'first.artist@example.com'])->id,
            'artist_emails' => ',first.artist@example.com,second.artist@example.com,',
        ]);

        $this->actingAs($secondArtist)
            ->get(route('songs.show', $song->song_code))
            ->assertOk();

        $this->actingAs($secondArtist)
            ->get(route('songs.index'))
            ->assertInertia(fn ($page) => $page->where('songs.data.0.song_code', $song->song_code));
    }

    public function test_unrelated_user_cannot_see_the_song(): void
    {
        $stranger = $this->makeUser('stranger@example.com');
        $song = $this->makeSong([
            'user_id' => User::factory()->create(['email' => 'owner@example.com'])->id,
            'artist_emails' => ',owner@example.com,',
        ]);

        $this->actingAs($stranger)
            ->get(route('songs.show', $song->song_code))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get(route('songs.index'))
            ->assertInertia(fn ($page) => $page->where('songs.total', 0));
    }

    public function test_history_shows_who_created_each_submit(): void
    {
        $creator = $this->makeUser('history.creator@example.com');
        $song = $this->makeSong(['user_id' => null, 'artist_emails' => null]);
        SongSubmission::create([
            'song_id' => $song->id,
            'payload' => ['track_title' => 'Visible Song'],
            'track_title' => 'Visible Song',
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator)
            ->get(route('songs.show', $song->song_code))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('submissions.0.created_by', $creator->name));
    }

    public function test_artist_emails_merge_across_submissions(): void
    {
        // The service unions emails from every submit into songs.artist_emails.
        $song = $this->makeSong(['user_id' => null, 'artist_emails' => ',old.artist@example.com,']);

        $service = app(\App\Services\SongSubmissionService::class);
        // Append (songCode present) so the new artist email is merged into
        // the existing songs.artist_emails list.
        $service->submit([
            'track_title' => 'Visible Song',
            'artists' => [
                ['name' => 'A', 'email' => 'new.artist@example.com'],
            ],
        ], $song->song_code, null);

        $fresh = Song::where('track_title', 'Visible Song')->orderByDesc('id')->first();
        $this->assertStringContainsString('old.artist@example.com', (string) $fresh->artist_emails);
        $this->assertStringContainsString('new.artist@example.com', (string) $fresh->artist_emails);
    }
}
