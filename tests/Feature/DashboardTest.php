<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /* ---- Viewer-timezone bucketing (same bug as the calendar) ---- */

    public function test_submissions_daily_buckets_use_the_viewer_timezone(): void
    {
        // Fixed clock: 2026-11-26 03:00 UTC = 2026-11-25 22:00 in UTC-5, so
        // for a UTC-5 viewer the submit belongs to the 25th, not the 26th.
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse('2026-11-26 03:00:00'));

        try {
            $song = Song::create([
                'song_code' => 'SONG-TZDASH1',
                'user_id' => $user->id,
                'track_title' => 'TZ Dashboard Track',
                'submission_count' => 1,
            ]);
            SongSubmission::create([
                'song_id' => $song->id,
                'track_title' => 'TZ Dashboard Track',
                'payload' => [],
                'created_by' => $user->id,
            ]);

            // Without a cookie: UTC buckets keep the historical behavior.
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertInertia(fn ($page) => $page
                    ->where('submissionsDaily.2026-11-26', 1)
                );

            $this->actingAs($user)
                ->withUnencryptedCookie('tz_offset', '300')
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('submissionsDaily.2026-11-25', 1)
                    ->missing('submissionsDaily.2026-11-26')
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_songs_this_month_uses_the_viewer_timezone(): void
    {
        // 2026-12-01 02:00 UTC is still November for a UTC-5 viewer: a song
        // created 2026-11-30 20:00 UTC is in THEIR current month.
        $user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse('2026-12-01 02:00:00'));

        try {
            Song::create([
                'song_code' => 'SONG-TZMONTH',
                'user_id' => $user->id,
                'track_title' => 'TZ Month Track',
                'submission_count' => 0,
            ]);
            // created_at is not mass-assignable — set it through the query
            // builder (timestamps are excluded from Song's fillable).
            Song::query()->where('song_code', 'SONG-TZMONTH')->update([
                'created_at' => '2026-11-30 20:00:00',
            ]);

            // Without a cookie: the UTC month is December, so the song
            // created on November 30 UTC does not count.
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertInertia(fn ($page) => $page->where('stats.songs_this_month', 0));

            $this->actingAs($user)
                ->withUnencryptedCookie('tz_offset', '300')
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('stats.songs_this_month', 1));
        } finally {
            Carbon::setTestNow();
        }
    }
}
