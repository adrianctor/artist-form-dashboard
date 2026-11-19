<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Spec S2 (odd/tasks/in-app-release-form.md, T7): system email that
 * replaces EmailJS on release-form submit. Recipients mirror the original
 * EmailJS config: to info@, cc all artist emails, reply_to first artist.
 *
 * Body: structured placeholder with every form field — swap for the
 * user-provided template when it arrives (pending since L1).
 */
class ReleaseFormSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload)
    {
    }

    public function envelope(): Envelope
    {
        $artists = $this->payload['artists'] ?? [];
        $emails = array_values(array_unique(array_filter(array_column($artists, 'email'))));

        // All addresses go through the constructor so they are normalized
        // to Address objects (direct property assignment skips that).
        return new Envelope(
            to: ['info@sunsetgathering.com'],
            cc: $emails,
            replyTo: $emails !== [] ? [$emails[0]] : [],
            subject: 'Music Release Form: '.($this->payload['track_title'] ?? 'New submission'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->renderBody(),
        );
    }

    /**
     * Structured placeholder body (pending the user's email template).
     */
    private function renderBody(): string
    {
        $p = $this->payload;
        $e = fn (string $v): string => e($v);

        $artistsHtml = '';
        foreach ($p['artists'] ?? [] as $i => $a) {
            $links = '';
            foreach (($a['links'] ?? []) as $platform => $url) {
                if (trim((string) $url) !== '') {
                    $links .= '<li>'.$e((string) $platform).': '.$e((string) $url).'</li>';
                }
            }
            $artistsHtml .= '
            <h3>Artist '.($i + 1).'</h3>
            <ul>
                <li><strong>Profile status:</strong> '.$e((string) ($a['status'] ?? '')).'</li>
                <li><strong>Name:</strong> '.$e((string) ($a['name'] ?? '')).'</li>
                <li><strong>Real name:</strong> '.$e((string) ($a['real_name'] ?? '')).'</li>
                <li><strong>Email:</strong> '.$e((string) ($a['email'] ?? '')).'</li>
                <li><strong>Role:</strong> '.$e((string) ($a['role'] ?? '')).'</li>
                <li><strong>Country:</strong> '.$e((string) ($a['country'] ?? '')).'</li>
                <li><strong>City:</strong> '.$e((string) ($a['city'] ?? '')).'</li>
                <li><strong>Estate:</strong> '.$e((string) ($a['estate'] ?? '')).'</li>
                <li><strong>Photo:</strong> '.$e((string) ($a['photo'] ?? '')).'</li>
                <li><strong>Bio:</strong> '.$e((string) ($a['bio'] ?? '')).'</li>
            </ul>
            '.($links !== '' ? '<ul>'.$links.'</ul>' : '');
        }

        $publishing = ($p['publishing'] ?? '') === 'afrodise' ? 'Afrodise' : "I don't have this";

        return '
        <div style="font-family:Arial,sans-serif;color:#333;">
            <h2>Music Release Form — new submission</h2>
            <table cellpadding="6" cellspacing="0" style="border-collapse:collapse">
                <tr><td><strong>Publishing</strong></td><td>'.$e($publishing).'</td></tr>
                <tr><td><strong>Track title</strong></td><td>'.$e((string) ($p['track_title'] ?? '')).'</td></tr>
                <tr><td><strong>Cover artwork creative direction</strong></td><td>'.$e((string) ($p['cover_artwork_creative_direction'] ?? '')).'</td></tr>
                <tr><td><strong>Audio master link</strong></td><td>'.$e((string) ($p['audio_link'] ?? '')).'</td></tr>
                <tr><td><strong>Genre</strong></td><td>'.$e((string) ($p['genre'] ?? '')).'</td></tr>
                <tr><td><strong>Country of recording</strong></td><td>'.$e((string) ($p['country_recording'] ?? '')).'</td></tr>
                <tr><td><strong>Audio style</strong></td><td>'.$e((string) ($p['audio_style'] ?? '')).'</td></tr>
                <tr><td><strong>Writers</strong></td><td>'.$e((string) ($p['writers'] ?? '')).'</td></tr>
                <tr><td><strong>Explicit content</strong></td><td>'.$e((string) ($p['explicit_content'] ?? '')).'</td></tr>
                <tr><td><strong>Is a cover</strong></td><td>'.$e((string) ($p['is_cover'] ?? '')).'</td></tr>
                '.(($p['is_cover'] ?? '') === 'Yes' ? '
                <tr><td><strong>Original song link</strong></td><td>'.$e((string) ($p['original_song_link'] ?? '')).'</td></tr>
                <tr><td><strong>Original track credits</strong></td><td>'.$e((string) ($p['original_track_credits'] ?? '')).'</td></tr>' : '').'
            </table>
            '.$artistsHtml.'
        </div>';
    }
}
