<?php

namespace Tests\Feature;

use App\Mail\ReleaseFormSubmitted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Spec: odd/tasks/in-app-release-form.md, T7, S2.
 *
 * Submitting the in-app form sends a system email (replaces EmailJS):
 * to info@sunsetgathering.com, cc artist emails, reply_to first artist —
 * mirroring the original EmailJS recipients. Body = the user's template
 * once provided; structured placeholder until then.
 */
class ReleaseFormEmailTest extends TestCase
{
    use RefreshDatabase;

    private function submitForm(array $override = []): void
    {
        $payload = array_merge([
            'publishing' => 'afrodise',
            'track_title' => 'Aaron Sevilla - Ya Zamani (Original Mix)',
            'cover_artwork_creative_direction' => 'Warm sunset colors.',
            'audio_link' => 'https://drive.google.com/file/master.wav',
            'genre' => 'Organic House',
            'country_recording' => 'Argentina',
            'audio_style' => 'Vocal',
            'writers' => 'Aaron Sevilla',
            'explicit_content' => 'No',
            'is_cover' => 'No',
            'original_song_link' => '',
            'original_track_credits' => '',
            'artists' => [
                [
                    'name' => 'Aaron Sevilla',
                    'real_name' => 'Aaron Sevilla Lopez',
                    'email' => 'first.artist@example.com',
                    'role' => 'Producer',
                    'country' => 'Argentina',
                    'city' => 'Buenos Aires',
                    'estate' => 'CABA',
                    'photo' => 'https://drive.google.com/photo.jpg',
                    'bio' => 'DJ since 2015.',
                    'status' => 'New Artist',
                    'links' => [],
                ],
                [
                    'name' => 'Badrops',
                    'real_name' => 'Badrops Real',
                    'email' => 'second.artist@example.com',
                    'role' => 'Vocalist',
                    'country' => 'Argentina',
                    'city' => 'Cordoba',
                    'estate' => 'Cordoba',
                    'photo' => 'https://drive.google.com/photo2.jpg',
                    'bio' => 'Vocalist since 2018.',
                    'status' => 'New Artist',
                    'links' => [],
                ],
            ],
        ], $override);

        $this->actingAs(User::factory()->create())
            ->post('/release-form', $payload)
            ->assertRedirect(route('release-form', ['submitted' => 1]));
    }

    public function test_submit_sends_the_system_email_with_original_recipients(): void
    {
        Mail::fake();

        $this->submitForm();

        Mail::assertSent(ReleaseFormSubmitted::class, function (ReleaseFormSubmitted $mail) {
            return $mail->hasTo('info@sunsetgathering.com')
                && $mail->hasCc('first.artist@example.com')
                && $mail->hasCc('second.artist@example.com')
                && $mail->hasReplyTo('first.artist@example.com');
        });
    }

    public function test_email_contains_the_form_data(): void
    {
        Mail::fake();

        $this->submitForm();

        Mail::assertSent(ReleaseFormSubmitted::class, function (ReleaseFormSubmitted $mail) {
            $rendered = $mail->render();

            return str_contains($rendered, 'Aaron Sevilla - Ya Zamani (Original Mix)')
                && str_contains($rendered, 'Organic House')
                && str_contains($rendered, 'Afrodise')
                && str_contains($rendered, 'first.artist@example.com');
        });
    }

    public function test_no_email_is_sent_when_validation_fails(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $this->actingAs($user)->post('/release-form', [
            'publishing' => 'afrodise',
            'track_title' => '',
        ]);

        Mail::assertNotSent(ReleaseFormSubmitted::class);
    }
}
