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

    public function test_non_admin_users_are_forbidden_from_the_calendar(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get('/calendar')->assertForbidden();
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
