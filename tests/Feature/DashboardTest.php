<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_lists_recent_submissions_and_no_version_labels(): void
    {
        // Spec: Submission activity shows real content (latest submits),
        // Recent songs never carries submission counts.
        $user = User::factory()->create();
        $song = Song::create([
            'song_code' => 'SONG-DASH001',
            'user_id' => $user->id,
            'track_title' => 'Dashboard Track',
            'submission_count' => 7,
        ]);
        SongSubmission::create([
            'song_id' => $song->id,
            'track_title' => 'Dashboard Track',
            'payload' => [],
            'created_by' => $user->id,
        ]);

        // A foreign song must not leak into the recent list.
        $foreignUser = User::factory()->create();
        $foreign = Song::create([
            'song_code' => 'SONG-FOREIGN9',
            'user_id' => $foreignUser->id,
            'track_title' => 'Foreign Track',
            'submission_count' => 1,
        ]);
        SongSubmission::create([
            'song_id' => $foreign->id,
            'track_title' => 'Foreign Track',
            'payload' => [],
            'created_by' => $foreignUser->id,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('recentSubmissions', 1)
                ->where('recentSubmissions.0.track_title', 'Dashboard Track')
                ->where('recentSubmissions.0.created_by', $user->name)
                ->where('recentSongs', fn ($songs) => $songs->pluck('song_code')->all() === ['SONG-DASH001'])
                ->missing('recentSongs.0.submission_count')
            );
    }

    public function test_music_release_form_route_is_reachable_from_the_dashboard(): void
    {
        // Spec S1: the form is internal now — no FORM_URL share needed.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));

        $this->actingAs($user)->get(route('release-form'))->assertOk();
    }
}
