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
            ->assertRedirect(route('calendar'));
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

    public function test_email_contains_every_non_empty_payload_field(): void
    {
        // Spec S7: the email must carry ALL the submit info — parity with
        // the song history / calendar detail.
        Mail::fake();

        $this->submitForm([
            'writers' => 'Writers PARITY Marker',
            'country_recording' => 'Country PARITY Marker',
            'genre' => 'Genre PARITY Marker',
        ]);

        Mail::assertSent(ReleaseFormSubmitted::class, function (ReleaseFormSubmitted $mail) {
            $rendered = $mail->render();

            return str_contains($rendered, 'Writers PARITY Marker')
                && str_contains($rendered, 'Country PARITY Marker')
                && str_contains($rendered, 'Genre PARITY Marker')
                // The card heading is the artist's real name (parity with
                // the history page), not a generic "Artist N".
                && str_contains($rendered, 'Aaron Sevilla')
                && str_contains($rendered, 'Badrops')
                && str_contains($rendered, 'Bio') // every artist field key is rendered
                && str_contains($rendered, 'DJ since 2015.');
        });
    }

    public function test_email_is_styled_like_the_page(): void
    {
        // Spec S12: dark card, uppercase headings, gray labels — same
        // visual language as the dashboard.
        Mail::fake();

        $this->submitForm();

        Mail::assertSent(ReleaseFormSubmitted::class, function (ReleaseFormSubmitted $mail) {
            $rendered = $mail->render();

            return str_contains($rendered, 'background-color:#000000')
                && str_contains($rendered, 'background-color:#0a0a0a')
                && str_contains($rendered, 'text-transform:uppercase')
                && str_contains($rendered, 'color:#a1a1aa')
                && str_contains($rendered, 'Music Release Form');
        });
    }

    public function test_email_renders_artist_links_as_anchors_never_json(): void
    {
        // Spec S13: platform links are clickable anchors with their
        // platform label — never a JSON blob.
        Mail::fake();

        $this->submitForm([
            'artists' => [[
                'name' => 'Link Artist',
                'real_name' => 'Link Artist',
                'email' => 'first.artist@example.com',
                'role' => 'DJ',
                'country' => 'AR',
                'city' => 'BA',
                'estate' => 'CABA',
                'photo' => 'https://x.com/p.jpg',
                'bio' => 'DJ since 2015.',
                'status' => 'New Artist',
                'links' => [
                    'spotify' => 'https://open.spotify.com/artist/xyz',
                    'apple_music' => '',
                ],
            ]],
        ]);

        Mail::assertSent(ReleaseFormSubmitted::class, function (ReleaseFormSubmitted $mail) {
            $rendered = $mail->render();

            return str_contains($rendered, 'href="https://open.spotify.com/artist/xyz"')
                && str_contains($rendered, 'Spotify')
                && str_contains($rendered, 'Platform links')
                && ! str_contains($rendered, '{"spotify"')
                && ! str_contains($rendered, '&quot;spotify&quot;');
        });
    }

    public function test_submit_recipient_can_be_overridden_via_config(): void
    {
        // The envelope target is env-driven (MAIL_SUBMIT_TO) so production
        // can point to a monitored mailbox while the default keeps the
        // spec address info@sunsetgathering.com.
        Mail::fake();
        config(['mail.submit_recipient' => 'monitor@djcare.world']);

        $this->submitForm();

        Mail::assertSent(ReleaseFormSubmitted::class, function (ReleaseFormSubmitted $mail) {
            return $mail->hasTo('monitor@djcare.world')
                && ! $mail->hasTo('info@sunsetgathering.com');
        });
    }

    public function test_email_is_responsive_on_narrow_screens(): void
    {
        // Spec: the email must adapt to phones — mobile media query, a
        // fluid 640px card, and labels that may wrap (no forced nowrap).
        Mail::fake();

        $this->submitForm();

        Mail::assertSent(ReleaseFormSubmitted::class, function (ReleaseFormSubmitted $mail) {
            $rendered = $mail->render();

            return str_contains($rendered, '@media only screen and (max-width:480px)')
                && str_contains($rendered, 'max-width:640px;width:100%')
                && str_contains($rendered, 'class="afd-label"')
                && ! str_contains($rendered, 'white-space:nowrap');
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
