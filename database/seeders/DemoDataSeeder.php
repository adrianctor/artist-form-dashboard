<?php

namespace Database\Seeders;

use App\Models\Song;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development only:
 * - Personal access token for the Elementor form (printed to stdout)
 * - Three sample songs with version history
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@sunsetgathering.com')->first() ?? User::first();

        if (! $admin) {
            $this->command?->error('No user found. Run RolesAndUsersSeeder first.');

            return;
        }

        $token = $admin->createToken('elementor-form')->plainTextToken;
        $this->command?->info('ELEMENTOR_FORM_TOKEN='.$token);

        if (Song::count() > 0) {
            $this->command?->info('Songs already present ('.Song::count().'), skipping demo songs.');

            return;
        }

        $demo = [
            ['SONG-DEMO0001', 'Aaron Sevilla, Badrops - Ya Zamani (Original Mix)', 3],
            ['SONG-DEMO0002', 'Nova Collective - Midnight Signal (Radio Edit)', 1],
            ['SONG-DEMO0003', 'Luna Rivas - Corazon de Neon', 2],
        ];

        foreach ($demo as [$code, $title, $versions]) {
            $song = Song::create([
                'song_code' => $code,
                'track_title' => $title,
                'submission_count' => 0,
            ]);

            for ($i = 1; $i <= $versions; $i++) {
                $song->submissions()->create([
                    'track_title' => $title,
                    'payload' => [
                        'track_title' => $title,
                        'genre' => ['Afro House', 'Deep House', 'Melodic Techno'][$i % 3],
                        'country_recording' => 'Argentina',
                        'audio_style' => 'Vocal',
                        'explicit_content' => 'No',
                        'is_cover' => 'No',
                        'writers' => 'Demo Writer',
                        'cover_artwork_creative_direction' => 'Neon gradients, retro textures',
                        'audio_link' => 'https://drive.google.com/file/d/demo',
                        'artist_count' => 1,
                        'artists' => [[
                            'name' => explode(' - ', $title)[0],
                            'real_name' => 'Demo Artist',
                            'email' => 'artist@example.com',
                            'role' => 'Producer',
                            'country' => 'Argentina',
                            'city' => 'Buenos Aires',
                            'estate' => 'CABA',
                            'photo' => 'https://example.com/photo.jpg',
                            'bio' => 'Demo artist bio for local development.',
                            'status' => 'Existing Artist',
                            'links' => [
                                'spotify' => 'https://open.spotify.com/artist/demo',
                                'apple_music' => 'https://music.apple.com/artist/demo',
                                'beatport' => 'https://www.beatport.com/artist/demo',
                                'youtube' => 'https://youtube.com/@demo',
                            ],
                        ]],
                    ],
                ]);
            }

            $song->update(['submission_count' => $versions]);
        }

        $this->command?->info('Demo songs created: '.Song::count());
    }
}
