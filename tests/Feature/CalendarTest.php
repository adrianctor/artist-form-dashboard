<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Database\Seeders\RolesAndUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Spec: odd/tasks/form-delivery-and-scale.md, T3, S2/S3/S4.
 *
 * Calendar for every user, month-scoped SQL aggregates, on-demand
 * paginated day detail — never loads the whole table.
 */
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndUsersSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeSongFor(User $owner, string $code, array $attrs = []): Song
    {
        $song = Song::create(array_merge([
            'song_code' => $code,
            'track_title' => 'Song '.$code,
            'submission_count' => 1,
            'user_id' => $owner->id,
        ], $attrs));

        SongSubmission::create([
            'song_id' => $song->id,
            'payload' => ['track_title' => $song->track_title],
            'track_title' => $song->track_title,
        ]);

        return $song;
    }

    public function test_guests_are_redirected_from_the_calendar(): void
    {
        $this->get('/calendar')->assertRedirect(route('login'));
    }

    public function test_non_admin_users_can_view_the_calendar(): void
    {
        // Spec S2: the calendar is for every user, not just the admin.
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get('/calendar')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Calendar'));
    }

    public function test_non_admin_only_sees_their_own_submits_on_the_calendar(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $owner = User::factory()->create();
        $owner->assignRole('user');

        $this->makeSongFor($user, 'SONG-MINE001');
        $this->makeSongFor($owner, 'SONG-THEIRS1');

        $today = now()->toDateString();

        $this->actingAs($user)->get('/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dayCounts.'.$today, 1)
            );

        $this->actingAs($owner)->get('/calendar')
            ->assertInertia(fn ($page) => $page->where('dayCounts.'.$today, 1));
    }

    public function test_admin_sees_every_submits_day_counts(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->makeSongFor($user, 'SONG-CAL0001');
        $this->makeSongFor($user, 'SONG-CAL0002');

        $today = now()->toDateString();

        $this->actingAs($admin)->get('/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Calendar')
                ->where('month', now()->format('Y-m'))
                ->where('dayCounts.'.$today, 2)
                ->where('day', null)
                ->where('dayPage', null)
            );
    }

    public function test_month_param_only_aggregates_that_month(): void
    {
        // Spec S4: only the requested month range is queried.
        $admin = $this->admin();
        $song = $this->makeSongFor($admin, 'SONG-OLD0001');

        $old = now()->subMonths(3);
        SongSubmission::where('song_id', $song->id)->update([
            'created_at' => $old->toDateTimeString(),
        ]);

        $this->actingAs($admin)->get('/calendar?month='.$old->format('Y-m'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('month', $old->format('Y-m'))
                ->where('dayCounts.'.$old->toDateString(), 1)
            );

        // Current month must not include the old submit.
        $this->actingAs($admin)->get('/calendar')
            ->assertInertia(fn ($page) => $page
                ->missing('dayCounts.'.$old->toDateString())
            );
    }

    public function test_invalid_month_falls_back_to_current_month(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/calendar?month=not-a-date')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('month', now()->format('Y-m')));
    }

    public function test_day_detail_is_loaded_on_demand(): void
    {
        // Spec S3: the day's submits come from ?day=, not from a bulk load.
        $admin = $this->admin();
        $this->makeSongFor($admin, 'SONG-DAY0001');

        $today = now()->toDateString();

        $this->actingAs($admin)->get('/calendar?day='.$today)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('day', $today)
                ->where('dayPage.data.0.song_code', 'SONG-DAY0001')
                ->where('dayPage.current_page', 1)
                ->where('dayPage.last_page', 1)
            );
    }

    public function test_day_detail_is_paginated(): void
    {
        // Spec S4: a busy day loads at most 50 rows per page.
        $admin = $this->admin();
        $song = $this->makeSongFor($admin, 'SONG-BUSY001');

        for ($i = 1; $i <= 60; $i++) {
            SongSubmission::create([
                'song_id' => $song->id,
                'payload' => ['track_title' => 'Busy Song'],
                'track_title' => 'Busy Song',
            ]);
        }

        $today = now()->toDateString();

        $this->actingAs($admin)->get('/calendar?day='.$today)
            ->assertInertia(fn ($page) => $page
                ->where('dayPage.data', fn ($data) => count($data) === 50)
                ->where('dayPage.current_page', 1)
                ->where('dayPage.last_page', 2)
            );

        $this->actingAs($admin)->get('/calendar?day='.$today.'&page=2')
            ->assertInertia(fn ($page) => $page
                ->where('dayPage.data', fn ($data) => count($data) === 11)
                ->where('dayPage.current_page', 2)
            );
    }

    public function test_day_outside_the_month_or_without_submits_returns_no_day_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/calendar?day=2000-01-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('day', null)
                ->where('dayPage', null)
            );
    }

    /* ---- Viewer-timezone bucketing (bug: submits landed on the wrong day) ---- */

    public function test_day_counts_use_the_viewer_timezone_not_utc(): void
    {
        // A submit at 2026-11-20 01:22 UTC is 2026-11-19 20:22 for a UTC-5
        // viewer — the reported bug: the calendar loaded it as the 20th.
        $admin = $this->admin();
        $song = $this->makeSongFor($admin, 'SONG-TZ000001');
        SongSubmission::where('song_id', $song->id)->update([
            'created_at' => '2026-11-20 01:22:00',
        ]);

        // No cookie (unknown viewer): UTC grouping is unchanged. This request
        // runs first because withUnencryptedCookie persists for every later
        // request in the same test.
        $this->actingAs($admin)
            ->get('/calendar?month=2026-11')
            ->assertInertia(fn ($page) => $page
                ->where('dayCounts.2026-11-20', 1)
                ->missing('dayCounts.2026-11-19')
            );

        // Viewer timezone (UTC-5): grouped under the 19th, never the 20th.
        $this->actingAs($admin)
            ->withUnencryptedCookie('tz_offset', '300')
            ->get('/calendar?month=2026-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tz', 300)
                ->where('dayCounts.2026-11-19', 1)
                ->missing('dayCounts.2026-11-20')
            );
    }

    public function test_day_detail_window_uses_the_viewer_timezone(): void
    {
        $admin = $this->admin();
        $song = $this->makeSongFor($admin, 'SONG-TZ000002');
        SongSubmission::where('song_id', $song->id)->update([
            'created_at' => '2026-11-20 01:22:00',
        ]);

        // The viewer's 19th (local 00:00–23:59 = UTC 05:00 → next 04:59) holds it.
        $this->actingAs($admin)
            ->withUnencryptedCookie('tz_offset', '300')
            ->get('/calendar?month=2026-11&day=2026-11-19')
            ->assertInertia(fn ($page) => $page
                ->where('day', '2026-11-19')
                ->where('dayPage.data.0.song_code', 'SONG-TZ000002')
            );

        // The viewer's 20th starts at UTC 05:00 on the 20th — not there.
        $this->actingAs($admin)
            ->withUnencryptedCookie('tz_offset', '300')
            ->get('/calendar?month=2026-11&day=2026-11-20')
            ->assertInertia(fn ($page) => $page
                ->where('dayPage.data', fn ($data) => count($data) === 0)
            );
    }

    public function test_month_bounds_respect_the_viewer_timezone(): void
    {
        // 2026-12-01 04:59 UTC is still 2026-11-30 23:59 for a UTC-5 viewer:
        // it belongs to November in the viewer's calendar, not December.
        $admin = $this->admin();
        $song = $this->makeSongFor($admin, 'SONG-TZ000003');
        SongSubmission::where('song_id', $song->id)->update([
            'created_at' => '2026-12-01 04:59:00',
        ]);

        $this->actingAs($admin)
            ->withUnencryptedCookie('tz_offset', '300')
            ->get('/calendar?month=2026-11')
            ->assertInertia(fn ($page) => $page
                ->where('dayCounts.2026-11-30', 1)
            );

        $this->actingAs($admin)
            ->withUnencryptedCookie('tz_offset', '300')
            ->get('/calendar?month=2026-12')
            ->assertInertia(fn ($page) => $page
                ->missing('dayCounts.2026-12-01')
            );
    }

    public function test_default_month_is_the_viewers_local_month(): void
    {
        // 2026-12-01 02:00 UTC is still 2026-11-30 21:00 for a UTC-5 viewer,
        // so the fallback month must be November for them, December for UTC.
        $admin = $this->admin();
        Carbon::setTestNow(Carbon::parse('2026-12-01 02:00:00'));

        try {
            // Without a cookie: UTC grouping keeps the historical month.
            $this->actingAs($admin)
                ->get('/calendar')
                ->assertInertia(fn ($page) => $page->where('month', '2026-12'));

            // With the viewer's offset: still November for a UTC-5 viewer.
            $this->actingAs($admin)
                ->withUnencryptedCookie('tz_offset', '300')
                ->get('/calendar')
                ->assertInertia(fn ($page) => $page->where('month', '2026-11'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
