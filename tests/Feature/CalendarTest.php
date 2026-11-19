<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->seed(\Database\Seeders\RolesAndUsersSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
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
}
