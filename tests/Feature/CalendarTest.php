<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('user', 'web');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
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

    public function test_non_admin_only_sees_their_own_songs_on_the_calendar(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $owner = User::factory()->create();
        $owner->assignRole('user');

        Song::create([
            'song_code' => 'SONG-MINE001',
            'track_title' => 'My Song',
            'submission_count' => 1,
            'user_id' => $user->id,
        ]);
        Song::create([
            'song_code' => 'SONG-THEIRS1',
            'track_title' => 'Someone Else Song',
            'submission_count' => 1,
            'user_id' => $owner->id,
        ]);

        $this->actingAs($user)->get('/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('songsByDate.'.now()->toDateString().'.0.song_code', 'SONG-MINE001')
                ->missing('songsByDate.'.now()->toDateString().'.1')
            );
    }

    public function test_admin_can_view_the_calendar_grouped_by_date(): void
    {
        $song = Song::create([
            'song_code' => 'SONG-CAL0001',
            'track_title' => 'Calendar Song',
            'submission_count' => 1,
        ]);

        $response = $this->actingAs($this->admin())->get('/calendar');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Calendar')
            ->has('songsByDate')
            ->where('songsByDate.'.now()->toDateString().'.0.song_code', $song->song_code)
        );
    }
}
