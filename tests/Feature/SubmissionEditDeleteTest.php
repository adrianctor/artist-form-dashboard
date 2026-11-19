<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Spec: odd/tasks/form-delivery-and-scale.md, T7, S11.
 *
 * Edit/delete a submit: ONLY the user who sent it (created_by) — the
 * artists who can merely see it (artist_emails) are excluded, even when
 * the creator is one of those artists. Strangers get 404 (view scope).
 */
class SubmissionEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndUsersSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole('user');

        return $user;
    }

    /** @return array{0: User, 1: Song, 2: SongSubmission} */
    private function makeSubmit(): array
    {
        $creator = $this->makeUser('submit.creator@example.com');

        $song = Song::create([
            'song_code' => 'SONG-EDIT001',
            'track_title' => 'Editable Song',
            'submission_count' => 1,
            'artist_emails' => ',submit.creator@example.com,featured.artist@example.com,',
        ]);

        $submission = SongSubmission::create([
            'song_id' => $song->id,
            'track_title' => 'Editable Song',
            'payload' => $this->validPayload(),
            'created_by' => $creator->id,
        ]);

        return [$creator, $song, $submission];
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'publishing' => 'afrodise',
            'track_title' => 'Editable Song',
            'cover_artwork_creative_direction' => 'Dark cover',
            'audio_link' => 'https://drive.google.com/file/x.wav',
            'genre' => 'House',
            'country_recording' => 'Argentina',
            'audio_style' => 'Vocal',
            'writers' => 'Some Writer',
            'explicit_content' => 'No',
            'is_cover' => 'No',
            'original_song_link' => '',
            'original_track_credits' => '',
            'artists' => [
                [
                    'name' => 'Submit Creator',
                    'real_name' => 'Submit Creator',
                    'email' => 'submit.creator@example.com',
                    'role' => 'DJ',
                    'country' => 'AR',
                    'city' => 'BA',
                    'estate' => 'CABA',
                    'photo' => 'https://x.com/p.jpg',
                    'bio' => 'Bio',
                    'status' => 'New Artist',
                    'links' => [],
                ],
                [
                    'name' => 'Featured Artist',
                    'real_name' => 'Featured Artist',
                    'email' => 'featured.artist@example.com',
                    'role' => 'Vocalist',
                    'country' => 'AR',
                    'city' => 'Cordoba',
                    'estate' => 'Cordoba',
                    'photo' => 'https://x.com/p2.jpg',
                    'bio' => 'Bio 2',
                    'status' => 'New Artist',
                    'links' => [],
                ],
            ],
        ], $override);
    }

    // --- edit page -------------------------------------------------------

    public function test_creator_can_open_the_edit_page(): void
    {
        [$creator, $song, $submission] = $this->makeSubmit();

        $this->actingAs($creator)
            ->get(route('submissions.edit', $submission->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ReleaseForm')
                ->where('editSubmission.id', $submission->id)
                ->where('editSubmission.payload.track_title', 'Editable Song')
            );
    }

    public function test_artist_who_can_view_cannot_open_the_edit_page(): void
    {
        // In the artist list (view access) but NOT the sender → 403.
        [, , $submission] = $this->makeSubmit();
        $featured = $this->makeUser('featured.artist@example.com');

        $this->actingAs($featured)
            ->get(route('submissions.edit', $submission->id))
            ->assertForbidden();
    }

    public function test_stranger_gets_404_on_the_edit_page(): void
    {
        [, , $submission] = $this->makeSubmit();
        $stranger = $this->makeUser('stranger@example.com');

        $this->actingAs($stranger)
            ->get(route('submissions.edit', $submission->id))
            ->assertNotFound();
    }

    // --- update ----------------------------------------------------------

    public function test_creator_can_update_a_submission(): void
    {
        [$creator, $song, $submission] = $this->makeSubmit();

        $payload = $this->validPayload([
            'track_title' => 'Edited Song Title',
            'genre' => 'Melodic Techno',
        ]);

        $this->actingAs($creator)
            ->from(route('submissions.edit', $submission->id))
            ->patch(route('submissions.update', $submission->id), $payload)
            ->assertRedirect(route('songs.show', $song->song_code));

        $fresh = $submission->fresh();
        $this->assertSame('Edited Song Title', $fresh->payload['track_title']);
        $this->assertSame('Melodic Techno', $fresh->payload['genre']);
        // The song's denormalized title follows the edit.
        $this->assertSame('Edited Song Title', $fresh->song->track_title);
        // Still one submit — editing never creates versions.
        $this->assertSame(1, $fresh->song->fresh()->submission_count);
    }

    public function test_update_recomputes_artist_emails(): void
    {
        [$creator, $song, $submission] = $this->makeSubmit();
        $this->assertStringContainsString('featured.artist@example.com', (string) $song->artist_emails);

        $payload = $this->validPayload();
        $payload['artists'][1]['email'] = 'renamed.artist@example.com';

        $this->actingAs($creator)
            ->patch(route('submissions.update', $submission->id), $payload)
            ->assertRedirect(route('songs.show', $song->song_code));

        $fresh = $song->fresh();
        // The union across all submits is recomputed: the removed email is
        // gone (only one submit exists) and the new one is present.
        $this->assertStringContainsString('renamed.artist@example.com', (string) $fresh->artist_emails);
        $this->assertStringNotContainsString('featured.artist@example.com', (string) $fresh->artist_emails);
    }

    public function test_featured_artist_cannot_update(): void
    {
        [, , $submission] = $this->makeSubmit();
        $featured = $this->makeUser('featured.artist@example.com');

        $this->actingAs($featured)
            ->patch(route('submissions.update', $submission->id), $this->validPayload())
            ->assertForbidden();

        $this->assertSame('Editable Song', $submission->fresh()->payload['track_title']);
    }

    public function test_update_with_invalid_payload_is_rejected(): void
    {
        [$creator, , $submission] = $this->makeSubmit();

        $this->actingAs($creator)
            ->from(route('submissions.edit', $submission->id))
            ->patch(route('submissions.update', $submission->id), $this->validPayload(['track_title' => '']))
            ->assertRedirect(route('submissions.edit', $submission->id))
            ->assertSessionHasErrors('track_title');

        $this->assertSame('Editable Song', $submission->fresh()->payload['track_title']);
    }

    // --- delete ----------------------------------------------------------

    public function test_creator_can_delete_a_submission(): void
    {
        [$creator, $song, $submission] = $this->makeSubmit();
        // A second submit so the song survives the delete.
        SongSubmission::create([
            'song_id' => $song->id,
            'track_title' => 'Editable Song',
            'payload' => $this->validPayload(),
            'created_by' => $creator->id,
        ]);
        $song->update(['submission_count' => 2]);

        $this->actingAs($creator)
            ->delete(route('submissions.destroy', $submission->id))
            ->assertRedirect(route('songs.show', $song->song_code));

        $this->assertDatabaseMissing('song_submissions', ['id' => $submission->id]);
        $this->assertSame(1, $song->fresh()->submission_count);
        $this->assertSame(1, $song->submissions()->count());
    }

    public function test_deleting_the_last_submission_removes_the_song(): void
    {
        [$creator, $song, $submission] = $this->makeSubmit();

        $this->actingAs($creator)
            ->delete(route('submissions.destroy', $submission->id))
            ->assertRedirect(route('songs.index'));

        $this->assertDatabaseMissing('songs', ['id' => $song->id]);
        $this->assertDatabaseMissing('song_submissions', ['id' => $submission->id]);

        $this->actingAs($creator)
            ->get(route('songs.show', $song->song_code))
            ->assertNotFound();
    }

    public function test_featured_artist_cannot_delete(): void
    {
        [, , $submission] = $this->makeSubmit();
        $featured = $this->makeUser('featured.artist@example.com');

        $this->actingAs($featured)
            ->delete(route('submissions.destroy', $submission->id))
            ->assertForbidden();

        $this->assertDatabaseHas('song_submissions', ['id' => $submission->id]);
    }

    public function test_stranger_gets_404_on_delete(): void
    {
        [, , $submission] = $this->makeSubmit();
        $stranger = $this->makeUser('stranger@example.com');

        $this->actingAs($stranger)
            ->delete(route('submissions.destroy', $submission->id))
            ->assertNotFound();
    }
}
